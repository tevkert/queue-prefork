<?php

namespace Tevkert\QueuePrefork\Support;

/**
 * The shared `queue:work` templates of this user, seen from outside: the status and
 * retire requests the commands send over each template's socket, and the active `.stock` markers.
 *
 * Templates live in a per-user runtime dir (RuntimeDir::sharedCandidates()): run the commands as the
 * workers' user.
 */
final class WorkTemplates
{
    /**
     * Status replies of the live templates, only those serving $release when given.
     *
     * @return list<array<string, mixed>>
     */
    public static function list(?string $release = null, float $timeout = 1.0): array
    {
        $templates = [];

        foreach (RuntimeDir::sharedCandidates() as $dir) {
            foreach (glob($dir.'/*.sock') ?: [] as $socket) {
                $reply = self::ask($socket, 'status', $timeout);

                if ($reply !== null && ($release === null || ($reply['release'] ?? null) === $release)) {
                    $templates[] = $reply + ['socket' => $socket];
                }
            }
        }

        return $templates;
    }

    /**
     * Active `.stock` markers (the breaker, or `logs-to-stdio`), only those for $release when given.
     *
     * @return list<array{key: string, reason: string, until: int, release: ?string}>
     */
    public static function markers(?string $release = null): array
    {
        $markers = [];

        foreach (array_merge(...array_map(fn ($dir) => glob($dir.'/*.stock') ?: [], RuntimeDir::sharedCandidates())) as $file) {
            $marker = json_decode((string) @file_get_contents($file), true);

            if (! is_array($marker) || (int) ($marker['until'] ?? 0) <= time()) {
                continue;
            }

            if ($release !== null && isset($marker['release']) && $marker['release'] !== $release) {
                continue;
            }

            $markers[] = [
                'key' => basename($file, '.stock'),
                'reason' => (string) ($marker['reason'] ?? ''),
                'until' => (int) $marker['until'],
                'release' => $marker['release'] ?? null,
            ];
        }

        return $markers;
    }

    /** Asks the template on this socket (from list()) to retire: new stubs launch a new one while it drains. */
    public static function retire(string $socket, float $timeout = 1.0): bool
    {
        return (self::ask($socket, 'retire', $timeout)['t'] ?? null) === 'retiring';
    }

    private static function ask(string $socket, string $type, float $timeout): ?array
    {
        $stream = @stream_socket_client('unix://'.$socket, $errno, $error, $timeout);

        if ($stream === false) {
            return null; // a stale socket, or a template that just retired
        }

        try {
            stream_set_timeout($stream, (int) ceil($timeout));

            if (@fwrite($stream, json_encode(['t' => $type])."\n") === false) {
                return null;
            }

            $line = @fgets($stream);
            $reply = is_string($line) ? json_decode($line, true) : null;

            return is_array($reply) ? $reply : null;
        } finally {
            fclose($stream);
        }
    }
}
