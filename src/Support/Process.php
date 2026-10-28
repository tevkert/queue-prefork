<?php

namespace Tevkert\QueuePrefork\Support;

final class Process
{
    /**
     * pcntl_fork(), with the child's stat and realpath caches cleared as PHP 8.4 clears them. Before 8.4
     * a child keeps its parent's, so a `/proc/self` path the parent resolved still names the parent.
     */
    public static function fork(): int
    {
        $pid = pcntl_fork();

        if ($pid === 0) {
            clearstatcache(true);
        }

        return $pid;
    }
}
