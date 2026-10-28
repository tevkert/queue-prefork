<?php

// tevkert/queue-prefork wiring, loaded through Composer's "files" autoload.
//
// Composer runs this in every process that loads the autoloader, web requests included, so it decides
// its role first without loading any class, and returns at once without one. With a role, it registers
// a single Artisan "starting" callback that does the container work (Wiring::starting).

(static function (): void {
    try {
        if (PHP_SAPI !== 'cli') {
            return;
        }

        $role = null;

        if (getenv('HP_ROLE') === 'probe') {
            $role = 'probe';
        } elseif (class_exists(\Tevkert\QueuePrefork\Runtime::class, false) && \Tevkert\QueuePrefork\Runtime::isChild()) {
            $role = 'child';
        } else {
            // The first non-option argument, as ArgvInput::getFirstArgument() finds it.
            $tokens = array_slice($_SERVER['argv'] ?? [], 1);

            for ($i = 0, $n = count($tokens); $i < $n; $i++) {
                $token = (string) $tokens[$i];

                if ($token === '' || $token[0] !== '-') {
                    if ($token === 'queue:work') {
                        // Per-worker fork: hand this worker to the shared template now,
                        // before Laravel boots. Returns only when this process should boot stock.
                        \Tevkert\QueuePrefork\Handoff::attempt();

                        return;
                    }

                    if ($token === 'horizon') {
                        // The master forked from a private template: exec into it now,
                        // keeping this pid. Returns only when the master starts as before.
                        \Tevkert\QueuePrefork\MasterHandoff::attempt();
                        $role = 'master';
                    }

                    break;
                }

                if ($token === '--env' || $token === '-e') {
                    $i++; // option with a separate value
                }
            }
        }

        if ($role === null) {
            return;
        }

        \Illuminate\Console\Application::starting(static function ($artisan) use ($role): void {
            \Tevkert\QueuePrefork\Wiring::starting($artisan, $role);
        });
    } catch (\Throwable) {
        // Never break the application: without wiring, workers and Horizon simply run stock.
    }
})();
