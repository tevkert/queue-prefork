<?php

namespace Tevkert\QueuePrefork\Client;

use Tevkert\QueuePrefork\Runtime;

/**
 * The crash breaker of one supervisor's forked workers: a unit's (Link), or an inline supervisor's (Master).
 *
 * Only workers that never reached their first loop, within two minutes of their spawn, and weren't stopped
 * by us, count. Enough of them within the window (breaker_exits, breaker_window) trip it.
 */
final class Breaker
{
    /** @var list<float> */
    private array $exits = [];

    /** Records a worker's exit: true when it trips the breaker. */
    public function trips(ForkedProcess $process, int $status, bool $wasReady): bool
    {
        if ($wasReady || $status === 0 || $process->parentSignalled || in_array($status, [137, 75, 72], true)) {
            return false;
        }

        $now = microtime(true);

        if ($now - $process->spawnedAt > 120) {
            return false;
        }

        $window = (float) Runtime::config('breaker_window', 300);
        $this->exits = array_values(array_filter($this->exits, fn (float $at) => $now - $at < $window));
        $this->exits[] = $now;

        return count($this->exits) >= (int) Runtime::config('breaker_exits', 5);
    }
}
