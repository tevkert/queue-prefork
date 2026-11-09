<?php

namespace Tevkert\QueuePrefork\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\StreamOutput;
use Tevkert\QueuePrefork\Support\LineBufferFilter;
use Tevkert\QueuePrefork\Support\LineBufferedOutput;

class LineBufferedOutputTest extends TestCase
{
    public function test_a_line_written_in_pieces_arrives_whole(): void
    {
        [$writer, $reader] = $this->filteredPair();

        fwrite($writer, '  2026-11-09 06:05:32 Laravel\Scout\Jobs\MakeSearchable');
        fwrite($writer, ' .........');
        $this->assertSame('', $this->readAvailable($reader));

        fwrite($writer, " RUNNING\n");
        $this->assertSame("  2026-11-09 06:05:32 Laravel\\Scout\\Jobs\\MakeSearchable ......... RUNNING\n", $this->readAvailable($reader));
    }

    public function test_complete_lines_pass_and_the_rest_waits(): void
    {
        [$writer, $reader] = $this->filteredPair();

        fwrite($writer, "one\ntwo\nthr");
        $this->assertSame("one\ntwo\n", $this->readAvailable($reader));

        fwrite($writer, "ee\n");
        $this->assertSame("three\n", $this->readAvailable($reader));
    }

    public function test_a_partial_line_is_passed_on_when_the_stream_closes(): void
    {
        [$writer, $reader] = $this->filteredPair();

        fwrite($writer, 'no newline');
        fclose($writer);

        $this->assertSame('no newline', $this->readAvailable($reader));
    }

    public function test_a_partial_line_is_not_held_past_the_limit(): void
    {
        [$writer, $reader] = $this->filteredPair();

        fwrite($writer, str_repeat('x', LineBufferFilter::MAX_HELD));

        $this->assertSame(LineBufferFilter::MAX_HELD, strlen($this->readAvailable($reader)));
    }

    public function test_a_console_output_on_a_filtered_stream_writes_whole_lines_and_attaching_twice_is_harmless(): void
    {
        [$writer, $reader] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        LineBufferedOutput::attachTo($writer);
        LineBufferedOutput::attachTo($writer);
        $output = new StreamOutput($writer);

        $output->write('half');
        $this->assertSame('', $this->readAvailable($reader));

        $output->writeln(' and half');
        $this->assertSame("half and half\n", $this->readAvailable($reader));
    }

    public function test_many_lines_in_one_write_each_pass_whole(): void
    {
        // A memory stream, not a socket: hundreds of small writes into a socket nobody reads yet can fill
        // its send buffer and block.
        $stream = fopen('php://memory', 'w+');
        LineBufferedOutput::attachTo($stream);
        $lines = '';

        for ($i = 0; $i < 2000; $i++) {
            $lines .= "line {$i}\n";
        }

        fwrite($stream, $lines.'partial');
        rewind($stream);

        $this->assertSame($lines, stream_get_contents($stream));
    }

    public function test_a_held_tail_is_passed_on_at_exit_and_the_exit_status_is_kept(): void
    {
        $result = $this->runPhp('
            LineBufferedOutput::attachToStdout();
            fwrite(STDOUT, "whole\ntail");
            exit(3);
        ');

        $this->assertSame([3, "whole\ntail", ''], $result);
    }

    public function test_stderr_is_left_alone_so_output_after_an_engine_fatal_error_survives(): void
    {
        // After a fatal error PHP no longer calls user filters, and a filtered stream drops what is
        // written to it; Laravel reports such an error on STDERR from a shutdown function.
        [$status, $out, $err] = $this->runPhp('
            LineBufferedOutput::attachToStdout();
            register_shutdown_function(fn () => fwrite(STDERR, "reported on stderr\n"));
            fwrite(STDERR, "stderr part");
            fwrite(STDOUT, "before\n");
            ini_set("memory_limit", "16M");
            $big = str_repeat("x", 64 * 1024 * 1024);
        ');

        $this->assertSame([255, "before\n"], [$status, $out]);
        $this->assertStringContainsString('Allowed memory size', $err);
        $this->assertStringStartsWith('stderr part', $err);
        $this->assertStringEndsWith("reported on stderr\n", $err);
    }

    public function test_output_from_later_shutdown_code_stays_line_buffered_and_its_tail_is_passed_on(): void
    {
        $result = $this->runPhp('
            LineBufferedOutput::attachToStdout();
            $GLOBALS["object"] = new class {
                public function __destruct() { fwrite(STDOUT, "destructor"); fwrite(STDOUT, " line\ndestructor tail"); }
            };
            register_shutdown_function(fn () => fwrite(STDOUT, "shutdown"));
            fwrite(STDOUT, "tail ");
        ');

        $this->assertSame([0, "tail shutdowndestructor line\ndestructor tail", ''], $result);
    }

    public function test_a_child_forked_after_attaching_does_not_repeat_what_its_parent_held(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('needs ext-pcntl');
        }

        // A job that forks (as the Concurrency fork driver does) while its process holds part of a line.
        [$status, $out, $err] = $this->runPhp('
            LineBufferedOutput::attachToStdout();
            fwrite(STDOUT, "parent start");
            $children = [];

            foreach (["silent", "talking"] as $kind) {
                $pid = pcntl_fork();

                if ($pid === 0) {
                    if ($kind === "talking") {
                        fwrite(STDOUT, "child line\nchild tail");
                    }

                    exit(0);
                }

                $children[] = $pid;
            }

            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
            }

            fwrite(STDOUT, " parent end\n");
        ');

        // The children are done before the parent finishes its line; the silent one writes nothing.
        $this->assertSame([0, "child line\nchild tailparent start parent end\n", ''], [$status, $out, $err]);
    }

    public function test_a_stream_closed_before_exit_leaves_the_exit_status_alone(): void
    {
        $result = $this->runPhp('
            LineBufferedOutput::attachToStdout();
            fwrite(STDOUT, "whole\ntail");
            fclose(STDOUT);
        ');

        $this->assertSame([0, "whole\ntail", ''], $result);
    }

    public function test_forked_writers_sharing_a_pipe_keep_their_lines_whole(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('needs ext-pcntl');
        }

        // As in a forked Horizon tree: each child filters the stdout it inherited and writes every line
        // in pieces, with pauses that let the others write in between.
        [$status, $out, $err] = $this->runPhp('
            $children = [];

            for ($w = 1; $w <= 4; $w++) {
                $pid = pcntl_fork();

                if ($pid === 0) {
                    pcntl_alarm(30); // a writer that hangs ends rather than holding the pipe open
                    LineBufferedOutput::attachToStdout();

                    for ($n = 1; $n <= 100; $n++) {
                        fwrite(STDOUT, "  worker {$w}");
                        usleep(random_int(0, 300));
                        fwrite(STDOUT, " line {$n} .........");
                        usleep(random_int(0, 300));
                        fwrite(STDOUT, " DONE\n");
                    }

                    exit(0);
                }

                $children[] = $pid;
            }

            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
            }
        ');

        $this->assertSame([0, ''], [$status, $err]);
        $lines = explode("\n", rtrim($out, "\n"));
        $this->assertCount(400, $lines);

        foreach ($lines as $line) {
            $this->assertMatchesRegularExpression('/^  worker [1-4] line \d+ \.{9} DONE$/', $line);
        }
    }

    /** @return array{0: int, 1: string, 2: string} exit status, stdout and stderr of a PHP process running $code */
    private function runPhp(string $code): array
    {
        // The alarm makes a hang fail the test instead of using up the CI job's time.
        $script = 'require $argv[1]; use Tevkert\QueuePrefork\Support\LineBufferedOutput; '
            .'if (function_exists("pcntl_alarm")) { pcntl_alarm(30); } '.$code;
        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-r', $script, dirname(__DIR__, 2).'/vendor/autoload.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out, $err];
    }

    /** @return array{0: resource, 1: resource} */
    private function filteredPair(): array
    {
        [$writer, $reader] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        LineBufferedOutput::attachTo($writer);

        return [$writer, $reader];
    }

    /** @param resource $reader */
    private function readAvailable($reader): string
    {
        stream_set_blocking($reader, false);
        usleep(20_000);
        $data = '';

        while (($chunk = fread($reader, 65536)) !== false && $chunk !== '') {
            $data .= $chunk;
        }

        return $data;
    }
}
