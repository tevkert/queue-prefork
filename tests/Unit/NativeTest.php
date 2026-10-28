<?php

namespace Tevkert\QueuePrefork\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tevkert\QueuePrefork\Support\Fd;
use Tevkert\QueuePrefork\Support\Native;

/**
 * The FFI bridge (spikes/ipc): run on Linux and on macOS.
 */
class NativeTest extends TestCase
{
    private Native $native;

    /** @var list<string> */
    private array $cleanup = [];

    protected function setUp(): void
    {
        if (! extension_loaded('ffi')) {
            $this->markTestSkipped('FFI is not loaded');
        }

        $this->assertTrue(Native::available(), (string) Native::reason());
        $this->native = Native::get();
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            @unlink($path);
        }
    }

    public function test_the_kernel_reports_the_connecting_process(): void
    {
        $path = $this->socketPath();
        $listener = stream_socket_server('unix://'.$path); // the template accepts PHP streams
        $client = pcntl_fork();

        if ($client === 0) {
            $fd = Native::get()->connect($path, 2.0);
            Native::get()->send($fd, "hello\n");
            usleep(500000);
            exit(0);
        }

        $conn = stream_socket_accept($listener, 2.0);
        $this->assertSame($client, $this->native->peerPid((int) Fd::number($conn)));

        fclose($conn);
        fclose($listener);
        pcntl_waitpid($client, $status);
    }

    public function test_descriptors_travel_with_the_message(): void
    {
        [$a, $b] = $this->native->socketPair();
        [$out, $outFd] = $this->tempFile();
        [$err, $errFd] = $this->tempFile();

        $this->assertSame(6, $this->native->sendWithFds($a, "spawn\n", [$outFd, $errFd]));
        $got = $this->native->receive($b, 1024, 4);

        $this->assertSame("spawn\n", $got['data']);
        $this->assertCount(2, $got['fds']);
        $this->assertFalse($got['truncated']);

        fwrite($w = fopen("php://fd/{$got['fds'][0]}", 'w'), "to out\n");
        fwrite($e = fopen("php://fd/{$got['fds'][1]}", 'w'), "to err\n");
        fclose($w);
        fclose($e);

        $this->assertSame("to out\n", file_get_contents($out));
        $this->assertSame("to err\n", file_get_contents($err));

        foreach ([...$got['fds'], $a, $b] as $fd) {
            $this->native->close($fd);
        }
    }

    public function test_a_message_larger_than_the_socket_buffer_arrives_whole_with_its_descriptors(): void
    {
        [$a, $b] = $this->native->socketPair();
        $this->native->setBlocking($a, false);
        $this->native->setBlocking($b, false);
        [, $fd] = $this->tempFile();
        $payload = str_repeat('0123456789abcdef', 65536)."\n"; // 1 MiB

        $sent = -1;
        $received = '';
        $fds = [];

        while (strlen($received) < strlen($payload)) {
            if ($sent < 0) {
                $sent = $this->native->sendWithFds($a, $payload, [$fd]);
            } elseif ($sent < strlen($payload)) {
                $n = $this->native->send($a, substr($payload, $sent));
                $sent += max(0, $n);
            }

            $got = $this->native->receive($b, 65536, 2);

            if ($got !== null) {
                $received .= $got['data'];
                $fds = [...$fds, ...$got['fds']];
            }
        }

        $this->assertSame($payload, $received);
        $this->assertCount(1, $fds, 'the descriptor arrives exactly once, with the first bytes');
        $this->native->close($fds[0]);
        $this->native->close($a);
        $this->native->close($b);
    }

    public function test_receive_timeouts_bound_the_call_and_clearing_them_waits_for_data(): void
    {
        [$a, $b] = $this->native->socketPair();
        $this->native->setTimeouts($b, 0.2);

        $t = microtime(true);
        $this->assertNull($this->native->receive($b), 'a receive timeout reads as "no data yet"');
        $this->assertEqualsWithDelta(0.2, microtime(true) - $t, 0.15);

        $this->native->setTimeouts($b, 0);
        $writer = pcntl_fork();

        if ($writer === 0) {
            usleep(500000);
            Native::get()->send($a, "late\n");
            exit(0);
        }

        $t = microtime(true);
        $got = $this->native->receive($b);
        $this->assertSame("late\n", $got['data'], 'without timeouts, the receive waits past 0.2 s');
        $this->assertGreaterThan(0.4, microtime(true) - $t);

        pcntl_waitpid($writer, $status);
        $this->native->close($a);
        $this->native->close($b);
    }

    public function test_eof_is_reported_when_the_peer_closes(): void
    {
        [$a, $b] = $this->native->socketPair();
        $this->native->close($a);

        $got = $this->native->receive($b);
        $this->assertTrue($got['eof']);
        $this->assertSame('', $got['data']);
        $this->native->close($b);
    }

    public function test_the_blocking_flag_round_trips(): void
    {
        [$a, $b] = $this->native->socketPair();

        $this->assertTrue($this->native->isBlocking($a));
        $this->native->setBlocking($a, false);
        $this->assertFalse($this->native->isBlocking($a));
        $this->native->setBlocking($a, true);
        $this->assertTrue($this->native->isBlocking($a));

        $this->native->close($a);
        $this->native->close($b);
    }

    public function test_dup_from_takes_the_lowest_free_descriptor_at_or_above_the_minimum(): void
    {
        [$a, $b] = $this->native->socketPair();

        $first = $this->native->dupFrom($a, 200);
        $second = $this->native->dupFrom($a, 200);

        $this->assertSame(200, $first);
        $this->assertSame(201, $second);

        foreach ([$first, $second, $a, $b] as $fd) {
            $this->native->close($fd);
        }
    }

    public function test_dup2_points_a_forked_childs_stdout_at_another_file(): void
    {
        [$file, $fd] = $this->tempFile();
        $child = pcntl_fork();

        if ($child === 0) {
            while (ob_get_level() > 0) {
                ob_end_clean(); // PHPUnit buffers output; a worker doesn't
            }

            Native::get()->dup2($fd, 1);
            echo "echo\n";
            fwrite(STDOUT, "STDOUT\n");
            passthru('echo subprocess');
            posix_kill(getmypid(), SIGKILL); // skip PHPUnit's shutdown in the child
        }

        pcntl_waitpid($child, $status);
        $this->assertSame("echo\nSTDOUT\nsubprocess\n", file_get_contents($file));
    }

    public function test_connect_failures_carry_the_errno(): void
    {
        try {
            $this->native->connect($this->socketPath(), 0.5);
            $this->fail('connecting to a missing socket must fail');
        } catch (RuntimeException $e) {
            $this->assertSame(2, $e->getCode(), 'ENOENT');
        }

        $stale = $this->socketPath();
        fclose(stream_socket_server('unix://'.$stale)); // the path stays, nobody listens

        try {
            $this->native->connect($stale, 0.5);
            $this->fail('connecting to a stale socket must fail');
        } catch (RuntimeException $e) {
            $this->assertSame(PHP_OS_FAMILY === 'Linux' ? 111 : 61, $e->getCode(), 'ECONNREFUSED');
        }
    }

    public function test_fd_number_finds_a_php_streams_descriptor(): void
    {
        [$file, $fd] = $this->tempFile();
        $copy = fopen("php://fd/{$fd}", 'w');
        fwrite($copy, "via number\n");
        fclose($copy);

        $this->assertSame("via number\n", file_get_contents($file));

        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $n = Fd::number($pair[0]);
        $this->assertNotNull($n);
        $this->native->send($n, "over the socket\n");
        $this->assertSame("over the socket\n", fread($pair[1], 100));
    }

    /** @return array{0: string, 1: int} path and native fd of a fresh temp file opened for writing */
    private function tempFile(): array
    {
        $path = tempnam(sys_get_temp_dir(), 'qp-native-');
        $this->cleanup[] = $path;
        $stream = fopen($path, 'w');
        $GLOBALS['__qp_native_keep'][] = $stream; // keep the descriptor open for the test's lifetime
        $fd = Fd::number($stream);
        $this->assertNotNull($fd);

        return [$path, $fd];
    }

    private function socketPath(): string
    {
        $path = sys_get_temp_dir().'/qpn-'.getmypid().'-'.bin2hex(random_bytes(3)).'.sock';
        $this->cleanup[] = $path;

        return $path;
    }
}
