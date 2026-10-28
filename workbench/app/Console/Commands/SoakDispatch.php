<?php

namespace App\Console\Commands;

use App\Jobs\Soak\CacheWork;
use App\Jobs\Soak\CollectionWork;
use App\Jobs\Soak\ImportWork;
use App\Jobs\Soak\DigestWork;
use App\Jobs\Soak\EloquentWork;
use App\Jobs\Soak\FeedRenderWork;
use App\Jobs\Soak\ImageWork;
use App\Jobs\Soak\JsonWork;
use App\Jobs\Soak\LlmWork;
use App\Jobs\Soak\MaintenanceWork;
use App\Jobs\Soak\NotifyWork;
use App\Jobs\Soak\ScoutWork;
use Illuminate\Console\Command;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Dispatches the soak's job stream at a fixed rate. The stream (queue, class, size and per-job seed of
 * every job) depends only on --seed, so two containers given the same seed do the same work.
 */
class SoakDispatch extends Command
{
    protected $signature = 'soak:dispatch
        {--rate=420 : jobs per minute, besides the digest bursts}
        {--duration=60 : minutes to run; 0 runs until stopped}
        {--seed=1 : seed of the job stream}
        {--mix=heavy : heavy (a production-shaped queue layout) or light (notifications, small writes, cache, short HTTP calls)}
        {--burst-every=5 : minutes of stream between digest bursts; 0 for none}
        {--burst-size=60 : jobs per digest burst}
        {--dry-run : print a summary of the stream instead of dispatching}';

    protected $description = 'Dispatch the deterministic soak job mix at a fixed rate';

    /**
     * The mix, shaped like a typical production app's demand (config/horizon.php), at about 420 jobs/min. Each
     * job exercises the packages a real job of that queue uses:
     *   import       ImportWork: stub feed (laminas-feed) and item pages (DiDom, mews/purifier,
     *                symfony/html-sanitizer), sluggable + activity-logged posts, a Scout index job
     *   media        ImageWork: intervention/image resize and encode, blurhash; CollectionWork
     *   ai           LlmWork: a laravel/ai structured completion (stub, 1.5-4 s), post update
     *   scout        ScoutWork: Scout searches (database engine), plus the import jobs' index jobs
     *   default      EloquentWork; FeedRenderWork: Livewire + Flux + Blaze render, schema.org
     *   notify       NotifyWork: database + log-mailer + web-push notifications; CacheWork
     *   maintenance  MaintenanceWork: bulk upsert, sitemap, valuestore
     * Digest bursts (DigestWork: Pennant + markdown mail render) are dispatched on top
     * (--burst-every, --burst-size).
     *
     * @var list<array{0: string, 1: class-string, 2: int, 3: int, 4: int}> [queue, job, weight, min size, max size]
     */
    public const MIX = [
        ['import', ImportWork::class, 100, 2, 5],          // requests (feed + pages)
        ['import', JsonWork::class, 10, 1, 1],            // MB parsed
        ['media', ImageWork::class, 10, 800, 1600],        // source width (px)
        ['media', CollectionWork::class, 6, 50_000, 50_000], // items
        ['ai', LlmWork::class, 12, 1, 1],                  // completions
        ['scout', ScoutWork::class, 30, 10, 40],           // searches × 10
        ['default', EloquentWork::class, 8, 10, 40],       // rows
        ['default', FeedRenderWork::class, 6, 300, 500],   // posts × 10
        ['notify', NotifyWork::class, 12, 1, 2],           // notifications
        ['notify', CacheWork::class, 6, 10, 20],           // reads
        ['maintenance', MaintenanceWork::class, 1, 1500, 3000], // rows
    ];

    /**
     * A typical light app's jobs on the same app: notifications (database, log mail, web push), small
     * database writes, cache reads and one short HTTP call each. No bursts.
     *
     * @var list<array{0: string, 1: class-string, 2: int, 3: int, 4: int}>
     */
    public const LIGHT_MIX = [
        ['notify', NotifyWork::class, 40, 1, 2],           // notifications
        ['default', EloquentWork::class, 30, 5, 20],       // rows
        ['notify', CacheWork::class, 20, 10, 20],          // reads
        ['import', ImportWork::class, 10, 1, 1],           // one fetch
    ];

    /** A digest burst: [queue, job, min size, max size] (posts × 20). */
    public const BURST = ['digest', DigestWork::class, 300, 500];

    public function handle(): int
    {
        $rate = max(0.1, (float) $this->option('rate'));
        $duration = max(0.0, (float) $this->option('duration'));
        $seed = (int) $this->option('seed');
        $interval = 60.0 / $rate;
        $total = $duration > 0 ? (int) round($rate * $duration) : PHP_INT_MAX;
        $random = new Randomizer(new Mt19937($seed));
        $bursts = new Randomizer(new Mt19937($seed + 1));
        // Bursts are placed by job index, not by clock, so the stream stays identical across containers.
        $mix = $this->option('mix') === 'light' ? self::LIGHT_MIX : self::MIX;
        $burstEvery = $mix === self::MIX ? (int) round($rate * max(0.0, (float) $this->option('burst-every'))) : 0;
        $burstSize = max(0, (int) $this->option('burst-size'));
        $weights = array_sum(array_column($mix, 2));
        $counts = [];

        $this->info(sprintf(
            'soak:dispatch mix=%s rate=%s/min duration=%s seed=%d (%s jobs)',
            $mix === self::MIX ? 'heavy' : 'light', $rate, $duration > 0 ? $duration.' min' : 'unbounded', $seed, $total === PHP_INT_MAX ? 'unbounded' : $total,
        ));

        $start = microtime(true);
        $lastReport = $start;

        for ($i = 0; $i < $total; $i++) {
            $jobs = [$this->next($mix, $random, $weights)];

            if ($burstEvery > 0 && $i > 0 && $i % $burstEvery === 0) {
                [$burstQueue, $burstClass, $min, $max] = self::BURST;

                for ($b = 0; $b < $burstSize; $b++) {
                    $jobs[] = [$burstQueue, $burstClass, $bursts->getInt($min, $max), $bursts->getInt(1, 2_000_000_000)];
                }
            }

            foreach ($jobs as [$queue, $class]) {
                $label = $queue.':'.class_basename($class);
                $counts[$label] = ($counts[$label] ?? 0) + 1;
            }

            if ($this->option('dry-run')) {
                if ($total === PHP_INT_MAX && $i >= 10_000) {
                    break;
                }

                continue;
            }

            $wait = $start + $i * $interval - microtime(true);

            if ($wait > 0) {
                usleep((int) ($wait * 1_000_000));
            }

            foreach ($jobs as [$queue, $class, $size, $jobSeed]) {
                dispatch((new $class($jobSeed, $size))->onQueue($queue));
            }

            if (microtime(true) - $lastReport >= 60) {
                $lastReport = microtime(true);
                $this->line(sprintf('[%s] dispatched %d', date('H:i:s'), $i + 1));
            }
        }

        ksort($counts);

        foreach ($counts as $label => $count) {
            $this->line(sprintf('  %-28s %6d', $label, $count));
        }

        $this->info(sprintf('done: %d jobs in %.1f min', array_sum($counts), (microtime(true) - $start) / 60));

        return self::SUCCESS;
    }

    /** @return array{0: string, 1: class-string, 2: int, 3: int} */
    private function next(array $mix, Randomizer $random, int $weights): array
    {
        $pick = $random->getInt(1, $weights);

        foreach ($mix as [$queue, $class, $weight, $min, $max]) {
            if (($pick -= $weight) <= 0) {
                return [$queue, $class, $random->getInt($min, $max), $random->getInt(1, 2_000_000_000)];
            }
        }

        throw new \LogicException('unreachable');
    }
}
