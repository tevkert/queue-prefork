<?php

namespace Tevkert\QueuePrefork\Console;

use Illuminate\Console\Command;
use Tevkert\QueuePrefork\Runtime;

/** Registered by the package's hook (not a provider): if this runs, the hook works in this boot. */
class ProbeCommand extends Command
{
    protected $signature = 'hp:probe {--role= : The role being probed}';

    protected $description = 'queue-prefork internal: confirms the package hook loads in this entry script';

    protected $hidden = true;

    public function handle(): int
    {
        $this->line(json_encode([
            'ok' => true,
            'role' => $this->option('role'),
            'protocol' => Runtime::PROTOCOL,
            'php' => PHP_VERSION,
        ]));

        return 0;
    }
}
