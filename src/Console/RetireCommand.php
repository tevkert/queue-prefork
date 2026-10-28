<?php

namespace Tevkert\QueuePrefork\Console;

use Illuminate\Console\Command;
use Tevkert\QueuePrefork\Support\RuntimeDir;
use Tevkert\QueuePrefork\Support\WorkTemplates;

/**
 * Retires this app's shared `queue:work` templates: each stops accepting at once, and
 * the next worker to start launches a fresh one, while the old one drains with its workers. Nothing is
 * killed; `queue:restart` then replaces the workers themselves.
 */
class RetireCommand extends Command
{
    protected $signature = 'queue-prefork:retire {--all : Every template of this user, not only this release\'s}';

    protected $description = 'Retire the queue:work templates, so new workers fork from a fresh one';

    public function handle(): int
    {
        $templates = WorkTemplates::list($this->option('all') ? null : (realpath(base_path()) ?: base_path()));

        if ($templates === []) {
            $where = implode(' or ', RuntimeDir::sharedCandidates());
            $this->components->info("No queue:work template is running for this release in {$where} (run this as the workers' user).");

            return 0;
        }

        foreach ($templates as $template) {
            $this->components->twoColumnDetail(
                "template {$template['pid']} ({$template['release']})",
                WorkTemplates::retire((string) $template['socket']) ? "retiring, {$template['workers']} workers draining" : 'already gone',
            );
        }

        $this->line('Run `php artisan queue:restart` to move the workers to the new template now.');

        return 0;
    }
}
