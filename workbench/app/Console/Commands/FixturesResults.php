<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Shows job_results (and failed_jobs counts), or clears them with --reset. */
class FixturesResults extends Command
{
    protected $signature = 'fixtures:results
        {--job= : only this job name (prefix match)}
        {--limit=50 : most recent rows}
        {--json : print rows as JSON lines, with full values}
        {--reset : truncate job_results, failed_jobs and job_batches instead}';

    protected $description = 'Show (or reset) the fixture jobs\' recorded outcomes';

    public function handle(): int
    {
        if ($this->option('reset')) {
            foreach (['job_results', 'failed_jobs', 'job_batches'] as $table) {
                DB::table($table)->truncate();
            }

            $this->info('job_results, failed_jobs and job_batches truncated');

            return self::SUCCESS;
        }

        $rows = DB::table('job_results')
            ->when($this->option('job'), fn ($q, $job) => $q->where('job', 'like', $job.'%'))
            ->orderByDesc('id')
            ->limit((int) $this->option('limit'))
            ->get()
            ->reverse();

        if ($this->option('json')) {
            foreach ($rows as $row) {
                $row->argv = json_decode($row->argv, true);
                $row->value = json_decode($row->value, true);
                $this->line(json_encode($row, JSON_UNESCAPED_SLASHES));
            }

            return self::SUCCESS;
        }

        $this->table(['id', 'job', 'pid', 'created_at', 'value'], $rows->map(fn ($row) => [
            $row->id, $row->job, $row->pid, $row->created_at, mb_strimwidth((string) $row->value, 0, 90, '…'),
        ])->all());

        $this->line('failed_jobs: '.DB::table('failed_jobs')->count());

        return self::SUCCESS;
    }
}
