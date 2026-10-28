<?php

namespace Tevkert\QueuePrefork;

/**
 * A forked Horizon worker's side of its startup socket.
 *
 * On the first Looping event (Laravel's worker installed its signal handlers just before) it tells its
 * fork server it is "ready". Until then, the desired pause state travels over the socket: a worker spawned during
 * a pause pauses itself before its first pop, so it never reserves a job.
 */
final class WorkerLink
{
    private static bool $readySent = false;

    public static function looping(): ?bool
    {
        $channel = Runtime::$channel;

        if ($channel !== null && ! self::$readySent) {
            foreach ($channel->receive() as $message) {
                if (($message['t'] ?? null) === 'pause_state') {
                    Runtime::$paused = (bool) ($message['paused'] ?? false);
                }
            }

            $channel->send(['t' => 'ready', 'id' => Runtime::$requestId]);
            $channel->flushBlocking(1.0);
            self::$readySent = true;
        }

        if (Runtime::$paused) {
            Runtime::$paused = false;
            posix_kill(getmypid(), SIGUSR2); // the worker's own handler marks it paused

            return false; // and this loop doesn't pop
        }

        return null;
    }
}
