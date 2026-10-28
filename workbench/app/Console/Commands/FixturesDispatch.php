<?php

namespace App\Console\Commands;

use App\Jobs\Crashes;
use App\Jobs\DispatchesBatch;
use App\Jobs\Dumps;
use App\Jobs\Fails;
use App\Jobs\LeaksMemory;
use App\Jobs\ReadsArgv;
use App\Jobs\ReadsConnections;
use App\Jobs\ReadsRandom;
use App\Jobs\Releases;
use App\Jobs\RunsConcurrency;
use App\Jobs\Sleeps;
use App\Jobs\Succeeds;
use App\Jobs\TimesOut;
use Illuminate\Console\Command;

/** Dispatches fixture jobs by name. With no names, every fixture except `crashes`. */
class FixturesDispatch extends Command
{
    protected $signature = 'fixtures:dispatch
        {fixtures?* : fixture names (see --list); default: all but crashes}
        {--queue=default : queue to dispatch on}
        {--times=1 : how many of each}
        {--sleep=3 : seconds for sleeps}
        {--hold=0 : seconds reads-random keeps its worker after drawing}
        {--leak=160 : megabytes for leaks-memory}
        {--crash=exit : exit or segv, for crashes}
        {--list : list the fixture names}';

    protected $description = 'Dispatch the workbench fixture jobs (outcomes land in job_results)';

    public function handle(): int
    {
        $fixtures = $this->fixtures();

        if ($this->option('list')) {
            foreach (array_keys($fixtures) as $name) {
                $this->line($name);
            }

            return self::SUCCESS;
        }

        $names = $this->argument('fixtures') ?: array_values(array_diff(array_keys($fixtures), ['crashes']));
        $unknown = array_diff($names, array_keys($fixtures));

        if ($unknown) {
            $this->error('Unknown fixture(s): '.implode(', ', $unknown).'. Known: '.implode(', ', array_keys($fixtures)));

            return self::FAILURE;
        }

        $queue = (string) $this->option('queue');

        foreach ($names as $name) {
            for ($i = 0; $i < max(1, (int) $this->option('times')); $i++) {
                dispatch($fixtures[$name]()->onQueue($queue));
            }

            $this->line("dispatched {$name} on {$queue}");
        }

        return self::SUCCESS;
    }

    /** @return array<string, \Closure(): object> */
    private function fixtures(): array
    {
        return [
            'succeeds' => fn () => new Succeeds('fixture'),
            'fails' => fn () => new Fails,
            'releases' => fn () => new Releases,
            'sleeps' => fn () => new Sleeps((int) $this->option('sleep')),
            'sleeps-steady' => fn () => new Sleeps((int) $this->option('sleep'), steady: true),
            'times-out' => fn () => new TimesOut,
            'leaks-memory' => fn () => new LeaksMemory((int) $this->option('leak')),
            'crashes' => fn () => new Crashes((string) $this->option('crash')),
            'reads-argv' => fn () => new ReadsArgv,
            'reads-random' => fn () => new ReadsRandom((float) $this->option('hold')),
            'reads-connections' => fn () => new ReadsConnections,
            'dumps' => fn () => new Dumps,
            'runs-concurrency' => fn () => new RunsConcurrency,
            'dispatches-batch' => fn () => new DispatchesBatch,
        ];
    }
}
