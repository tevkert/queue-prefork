<?php

namespace Tevkert\QueuePrefork\Support;

use FFI;
use FFI\CData;
use RuntimeException;
use Throwable;

/**
 * The libc calls PHP doesn't expose, through FFI: descriptor passing (SCM_RIGHTS),
 * the kernel-verified peer pid, socketpair, dup2 and F_DUPFD, socket timeouts and O_NONBLOCK on native
 * descriptors. Dependency-free.
 *
 * Two layouts are supported: Linux on LP64 (glibc and musl agree on these structs when little-endian:
 * musl's int-plus-padding fields read the same as glibc's size_t ones) and Darwin. Anything else
 * reports itself unavailable, and the caller runs stock.
 */
final class Native
{
    private const LINUX = '
        struct iovec { void *iov_base; size_t iov_len; };
        struct msghdr { void *msg_name; uint32_t msg_namelen; struct iovec *msg_iov; size_t msg_iovlen;
                        void *msg_control; size_t msg_controllen; int msg_flags; };
        struct sockaddr_un { uint16_t sun_family; char sun_path[108]; };
        struct ucred { int32_t pid; uint32_t uid; uint32_t gid; };
        struct timeval { long tv_sec; long tv_usec; };
        int *__errno_location(void);';

    private const DARWIN = '
        struct iovec { void *iov_base; size_t iov_len; };
        struct msghdr { void *msg_name; uint32_t msg_namelen; struct iovec *msg_iov; int msg_iovlen;
                        void *msg_control; uint32_t msg_controllen; int msg_flags; };
        struct sockaddr_un { uint8_t sun_len; uint8_t sun_family; char sun_path[104]; };
        struct timeval { long tv_sec; int32_t tv_usec; };
        int *__error(void);';

    private const COMMON = '
        int socket(int domain, int type, int protocol);
        int socketpair(int domain, int type, int protocol, int sv[2]);
        int connect(int fd, const void *addr, uint32_t len);
        long send(int fd, const void *buf, size_t len, int flags);
        long sendmsg(int fd, const struct msghdr *msg, int flags);
        long recvmsg(int fd, struct msghdr *msg, int flags);
        int getsockopt(int fd, int level, int name, void *value, uint32_t *len);
        int setsockopt(int fd, int level, int name, const void *value, uint32_t len);
        int fcntl(int fd, int cmd, ...);
        int dup2(int from, int to);
        int close(int fd);';

    /** Per-platform constants. */
    /** php_uname('m') values per OS whose layout and numbering CONSTANTS describes. */
    private const MACHINES = [
        'Linux' => ['x86_64', 'amd64', 'aarch64', 'arm64', 'riscv64', 'loongarch64'],
        'Darwin' => ['arm64', 'x86_64'],
    ];

    private const CONSTANTS = [
        'Linux' => [
            'SOL_SOCKET' => 1, 'SCM_RIGHTS' => 1, 'SO_PEERCRED' => 17, 'SO_RCVTIMEO' => 20, 'SO_SNDTIMEO' => 21,
            'O_NONBLOCK' => 0o4000, 'MSG_NOSIGNAL' => 0x4000, 'MSG_CTRUNC' => 0x8, 'EAGAIN' => 11, 'EINTR' => 4,
            'CMSG_ALIGN' => 8, 'CMSG_HDR' => 16, 'PATH_MAX' => 107,
        ],
        'Darwin' => [
            'SOL_SOCKET' => 0xFFFF, 'SCM_RIGHTS' => 1, 'SOL_LOCAL' => 0, 'LOCAL_PEERPID' => 2,
            'SO_RCVTIMEO' => 0x1006, 'SO_SNDTIMEO' => 0x1005, 'SO_NOSIGPIPE' => 0x1022,
            'O_NONBLOCK' => 0x4, 'MSG_NOSIGNAL' => 0, 'MSG_CTRUNC' => 0x20, 'EAGAIN' => 35, 'EINTR' => 4,
            'CMSG_ALIGN' => 4, 'CMSG_HDR' => 12, 'PATH_MAX' => 103,
        ],
    ];

    private const AF_UNIX = 1;

    private const SOCK_STREAM = 1;

    private const F_DUPFD = 0;

    private const F_GETFL = 3;

    private const F_SETFL = 4;

    private static ?self $instance = null;

    private static ?string $unavailable = null;

    /** @var array<string, int> */
    private array $c;

    private bool $linux;

    private function __construct(private FFI $ffi, string $os)
    {
        $this->c = self::CONSTANTS[$os];
        $this->linux = $os === 'Linux';
    }

    /** True when FFI is loaded, allowed in this SAPI, and this platform's layout is known. */
    public static function available(): bool
    {
        if (self::$instance !== null) {
            return true;
        }

        if (self::$unavailable !== null) {
            return false;
        }

        try {
            self::$instance = self::load();
        } catch (Throwable $e) {
            self::$unavailable = $e->getMessage();
        }

        return self::$instance !== null;
    }

    /** Why the bridge is unavailable, or null when it is available. */
    public static function reason(): ?string
    {
        return self::available() ? null : self::$unavailable;
    }

    public static function get(): self
    {
        if (! self::available()) {
            throw new RuntimeException('FFI bridge unavailable: '.self::$unavailable);
        }

        return self::$instance;
    }

    private static function load(): self
    {
        if (! extension_loaded('ffi') || ! class_exists(FFI::class)) {
            throw new RuntimeException('the FFI extension is not loaded');
        }

        $enable = strtolower((string) ini_get('ffi.enable'));

        if (! in_array($enable, ['1', 'on', 'true', 'yes'], true) && ! ($enable === 'preload' && PHP_SAPI === 'cli')) {
            throw new RuntimeException("ffi.enable={$enable} does not allow FFI here");
        }

        $os = PHP_OS_FAMILY;
        $machine = strtolower(php_uname('m'));

        // The constants are those of x86-64 and of the asm-generic architectures; others (ppc64le,
        // mips64el, ...) number their socket options and flags differently.
        if (! isset(self::CONSTANTS[$os]) || PHP_INT_SIZE !== 8 || pack('S', 1) !== "\x01\x00"
            || ! in_array($machine, self::MACHINES[$os], true)) {
            throw new RuntimeException('no FFI layout for '.$os.'/'.$machine);
        }

        $native = new self(FFI::cdef(($os === 'Linux' ? self::LINUX : self::DARWIN).self::COMMON), $os);
        $native->selfTest();

        return $native;
    }

    /** What the layout must get right, on a live socket pair: the peer's pid, and a timeout read back. */
    private function selfTest(): void
    {
        [$a, $b] = $this->socketPair();

        try {
            $this->setTimeouts($a, 2.0); // whole seconds: Linux keeps it in jiffies, exact at any HZ
            $tv = $this->ffi->new('struct timeval');
            $len = $this->ffi->new('uint32_t');
            $len->cdata = FFI::sizeof($tv);
            $read = $this->ffi->getsockopt($a, $this->c['SOL_SOCKET'], $this->c['SO_RCVTIMEO'], FFI::addr($tv), FFI::addr($len)) === 0;

            if ($this->peerPid($b) !== getmypid() || ! $read || $tv->tv_sec !== 2 || $tv->tv_usec > 20000) {
                throw new RuntimeException('the FFI layout self-test failed on '.PHP_OS_FAMILY.'/'.php_uname('m'));
            }
        } finally {
            $this->close($a);
            $this->close($b);
        }
    }

    public function errno(): int
    {
        return $this->linux ? $this->ffi->__errno_location()[0] : $this->ffi->__error()[0];
    }

    /**
     * A blocking connection to $path whose every send and receive is bounded by $timeout seconds
     * (SO_SNDTIMEO/SO_RCVTIMEO), so a stalled peer can't strand the caller.
     *
     * @throws RuntimeException with the errno as its code (ENOENT: no socket; ECONNREFUSED: stale or,
     *                          on Darwin, a full listen queue; EAGAIN: timed out)
     */
    public function connect(string $path, float $timeout): int
    {
        $fd = $this->socket();
        $addr = $this->address($path);
        $this->setTimeouts($fd, $timeout);

        if ($this->ffi->connect($fd, FFI::addr($addr), FFI::sizeof($addr)) !== 0) {
            $this->fail('connect '.$path, $fd);
        }

        return $fd;
    }

    /** @return array{0: int, 1: int} a connected pair of stream sockets */
    public function socketPair(): array
    {
        $pair = $this->ffi->new('int[2]');

        if ($this->ffi->socketpair(self::AF_UNIX, self::SOCK_STREAM, 0, $pair) !== 0) {
            $this->fail('socketpair');
        }

        $this->noSigpipe($pair[0]);
        $this->noSigpipe($pair[1]);

        return [$pair[0], $pair[1]];
    }

    /** The kernel's record of the connected peer's pid (SO_PEERCRED / LOCAL_PEERPID), or null. */
    public function peerPid(int $fd): ?int
    {
        $len = $this->ffi->new('uint32_t');

        if ($this->linux) {
            $cred = $this->ffi->new('struct ucred');
            $len->cdata = FFI::sizeof($cred);
            $ok = $this->ffi->getsockopt($fd, $this->c['SOL_SOCKET'], $this->c['SO_PEERCRED'], FFI::addr($cred), FFI::addr($len)) === 0;

            return $ok && $cred->pid > 0 ? $cred->pid : null;
        }

        $pid = $this->ffi->new('int32_t');
        $len->cdata = 4;
        $ok = $this->ffi->getsockopt($fd, $this->c['SOL_LOCAL'], $this->c['LOCAL_PEERPID'], FFI::addr($pid), FFI::addr($len)) === 0;

        return $ok && $pid->cdata > 0 ? $pid->cdata : null;
    }

    /**
     * Sends $data with $fds attached (SCM_RIGHTS) in one sendmsg. Returns the bytes sent, which may be
     * fewer than strlen($data) on a full socket: send the rest with send(); the descriptors travel with
     * the first byte. Returns -1 when nothing could be sent (see errno()).
     *
     * @param  list<int>  $fds
     */
    public function sendWithFds(int $fd, string $data, array $fds): int
    {
        if ($data === '') {
            throw new RuntimeException('sendWithFds needs at least one byte to carry the descriptors.');
        }

        $buffer = $this->ffi->new('char['.strlen($data).']');
        FFI::memcpy($buffer, $data, strlen($data));
        $iov = $this->ffi->new('struct iovec');
        $iov->iov_base = FFI::addr($buffer[0]);
        $iov->iov_len = strlen($data);
        $msg = $this->ffi->new('struct msghdr');
        $msg->msg_iov = FFI::addr($iov);
        $msg->msg_iovlen = 1;

        if ($fds !== []) {
            $control = $this->control(count($fds));
            $this->writeHeader($control, 0, $this->c['CMSG_HDR'] + 4 * count($fds), $this->c['SOL_SOCKET'], $this->c['SCM_RIGHTS']);
            $ints = $this->ffi->cast('int *', FFI::addr($control[$this->align($this->c['CMSG_HDR'])]));

            foreach (array_values($fds) as $i => $each) {
                $ints[$i] = $each;
            }

            $msg->msg_control = FFI::addr($control[0]);
            $msg->msg_controllen = FFI::sizeof($control);
        }

        return $this->ffi->sendmsg($fd, FFI::addr($msg), $this->c['MSG_NOSIGNAL']);
    }

    /** Sends as much of $data as the socket takes now; -1 when it takes nothing (see errno()). */
    public function send(int $fd, string $data): int
    {
        if ($data === '') {
            return 0;
        }

        $buffer = $this->ffi->new('char['.strlen($data).']');
        FFI::memcpy($buffer, $data, strlen($data));

        return $this->ffi->send($fd, $buffer, strlen($data), $this->c['MSG_NOSIGNAL']);
    }

    /**
     * Receives up to $max bytes and up to $maxFds descriptors.
     *
     * @return array{data: string, fds: list<int>, eof: bool, truncated: bool}|null null when nothing is
     *                                                                              available yet (EAGAIN, EINTR, or a receive timeout)
     */
    public function receive(int $fd, int $max = 65536, int $maxFds = 4): ?array
    {
        $buffer = $this->ffi->new("char[{$max}]");
        $iov = $this->ffi->new('struct iovec');
        $iov->iov_base = FFI::addr($buffer[0]);
        $iov->iov_len = $max;
        $control = $this->control($maxFds);
        $msg = $this->ffi->new('struct msghdr');
        $msg->msg_iov = FFI::addr($iov);
        $msg->msg_iovlen = 1;
        $msg->msg_control = FFI::addr($control[0]);
        $msg->msg_controllen = FFI::sizeof($control);

        $n = $this->ffi->recvmsg($fd, FFI::addr($msg), 0);

        if ($n < 0) {
            // errno 0: an async signal handler ran between the call and this read and clobbered it,
            // which only happens when the call was interrupted.
            if (in_array($this->errno(), [0, $this->c['EAGAIN'], $this->c['EINTR']], true)) {
                return null;
            }

            $this->fail('recvmsg');
        }

        return [
            'data' => $n > 0 ? FFI::string($buffer, $n) : '',
            'fds' => $this->descriptors($control, (int) $msg->msg_controllen),
            'eof' => $n === 0,
            'truncated' => ($msg->msg_flags & $this->c['MSG_CTRUNC']) !== 0,
        ];
    }

    /** Bounds every send and receive on $fd by $seconds; 0 removes the bounds. */
    public function setTimeouts(int $fd, float $seconds): void
    {
        $tv = $this->ffi->new('struct timeval');
        $tv->tv_sec = (int) $seconds;
        $tv->tv_usec = (int) round(($seconds - (int) $seconds) * 1e6);

        foreach (['SO_RCVTIMEO', 'SO_SNDTIMEO'] as $option) {
            if ($this->ffi->setsockopt($fd, $this->c['SOL_SOCKET'], $this->c[$option], FFI::addr($tv), FFI::sizeof($tv)) !== 0) {
                $this->fail('setsockopt '.$option);
            }
        }
    }

    public function setBlocking(int $fd, bool $blocking): void
    {
        $flags = $this->ffi->fcntl($fd, self::F_GETFL);

        if ($flags < 0) {
            $this->fail('fcntl F_GETFL');
        }

        $wanted = $blocking ? $flags & ~$this->c['O_NONBLOCK'] : $flags | $this->c['O_NONBLOCK'];

        if ($wanted !== $flags && $this->ffi->fcntl($fd, self::F_SETFL, $wanted) < 0) {
            $this->fail('fcntl F_SETFL');
        }
    }

    public function isBlocking(int $fd): bool
    {
        $flags = $this->ffi->fcntl($fd, self::F_GETFL);

        if ($flags < 0) {
            $this->fail('fcntl F_GETFL');
        }

        return ($flags & $this->c['O_NONBLOCK']) === 0;
    }

    /** Duplicates $fd onto the lowest free descriptor >= $min (F_DUPFD; the copy is inherited by exec). */
    public function dupFrom(int $fd, int $min): int
    {
        $copy = $this->ffi->fcntl($fd, self::F_DUPFD, $min);

        if ($copy < 0) {
            $this->fail('fcntl F_DUPFD');
        }

        return $copy;
    }

    public function dup2(int $from, int $to): void
    {
        if ($this->ffi->dup2($from, $to) < 0) {
            $this->fail("dup2 {$from} {$to}");
        }
    }

    public function close(int $fd): void
    {
        $this->ffi->close($fd);
    }

    private function socket(): int
    {
        $fd = $this->ffi->socket(self::AF_UNIX, self::SOCK_STREAM, 0);

        if ($fd < 0) {
            $this->fail('socket');
        }

        $this->noSigpipe($fd);

        return $fd;
    }

    /** Darwin has no MSG_NOSIGNAL; the socket option does the same there. */
    private function noSigpipe(int $fd): void
    {
        if ($this->linux) {
            return;
        }

        $one = $this->ffi->new('int');
        $one->cdata = 1;
        $this->ffi->setsockopt($fd, $this->c['SOL_SOCKET'], $this->c['SO_NOSIGPIPE'], FFI::addr($one), 4);
    }

    private function address(string $path): CData
    {
        if (strlen($path) > $this->c['PATH_MAX']) {
            throw new RuntimeException("Socket path longer than {$this->c['PATH_MAX']} bytes: {$path}");
        }

        $addr = $this->ffi->new('struct sockaddr_un');

        if (! $this->linux) {
            $addr->sun_len = FFI::sizeof($addr);
        }

        $addr->sun_family = self::AF_UNIX;
        FFI::memcpy($addr->sun_path, $path, strlen($path));

        return $addr;
    }

    private function align(int $n): int
    {
        $a = $this->c['CMSG_ALIGN'];

        return ($n + $a - 1) & ~($a - 1);
    }

    /** A zeroed control buffer with room for one SCM_RIGHTS header and $fds descriptors. */
    private function control(int $fds): CData
    {
        return $this->ffi->new('uint8_t['.($this->align($this->c['CMSG_HDR']) + $this->align(4 * max(1, $fds))).']');
    }

    private function writeHeader(CData $control, int $offset, int $len, int $level, int $type): void
    {
        if ($this->linux) {
            $this->ffi->cast('size_t *', FFI::addr($control[$offset]))[0] = $len;
            $ints = $this->ffi->cast('int *', FFI::addr($control[$offset + 8]));
        } else {
            $this->ffi->cast('uint32_t *', FFI::addr($control[$offset]))[0] = $len;
            $ints = $this->ffi->cast('int *', FFI::addr($control[$offset + 4]));
        }

        $ints[0] = $level;
        $ints[1] = $type;
    }

    /** @return list<int> the SCM_RIGHTS descriptors in a received control buffer */
    private function descriptors(CData $control, int $length): array
    {
        $fds = [];
        $offset = 0;
        $hdr = $this->c['CMSG_HDR'];

        while ($offset + $hdr <= $length) {
            if ($this->linux) {
                $len = (int) $this->ffi->cast('size_t *', FFI::addr($control[$offset]))[0];
                $ints = $this->ffi->cast('int *', FFI::addr($control[$offset + 8]));
            } else {
                $len = (int) $this->ffi->cast('uint32_t *', FFI::addr($control[$offset]))[0];
                $ints = $this->ffi->cast('int *', FFI::addr($control[$offset + 4]));
            }

            if ($len < $hdr) {
                break;
            }

            if ($ints[0] === $this->c['SOL_SOCKET'] && $ints[1] === $this->c['SCM_RIGHTS']) {
                $data = $this->ffi->cast('int *', FFI::addr($control[$offset + $this->align($hdr)]));

                for ($i = 0, $n = intdiv($len - $hdr, 4); $i < $n; $i++) {
                    $fds[] = $data[$i];
                }
            }

            $offset += $this->align($len);
        }

        return $fds;
    }

    private function fail(string $what, ?int $close = null): never
    {
        $errno = $this->errno();

        if ($close !== null) {
            $this->ffi->close($close);
        }

        throw new RuntimeException($what.': '.(function_exists('posix_strerror') ? posix_strerror($errno) : "errno {$errno}"), $errno);
    }
}
