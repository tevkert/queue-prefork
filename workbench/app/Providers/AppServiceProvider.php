<?php

namespace App\Providers;

use App\Livewire\PostFeed;
use App\Models\User;
use App\Support\SoakStats;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Nightwatch\Facades\Nightwatch;
use Laravel\Pennant\Feature;
use Laravel\Pulse\Entry;
use Laravel\Pulse\Facades\Pulse;
use Laravel\Pulse\Value;
use Livewire\Blaze\Blaze;
use Livewire\Livewire;
use SocialiteProviders\Discord\DiscordExtendSocialite;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Twitch\TwitchExtendSocialite;

/**
 * The app's own wiring, modelled on a production AppServiceProvider: Nightwatch filters and
 * sampling, Pulse user resolution, Socialite providers, Livewire components, Blaze, Pennant features.
 */
class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Throughput and runtime counters for the soak sampler (soak/sample.php reads them from Redis).
        if ($this->app->runningInConsole()) {
            Event::listen(JobProcessing::class, [SoakStats::class, 'processing']);
            Event::listen(JobProcessed::class, [SoakStats::class, 'processed']);
            Event::listen(JobFailed::class, [SoakStats::class, 'failed']);

            // Test switch (QueueWorkParityTest): a Looping listener that returns a value, registered
            // before any package's, so Laravel's `until` never reaches theirs.
            if (is_file(storage_path('framework/workbench-swallow-looping'))) {
                Event::listen(Looping::class, fn () => true);
            }
        }

        $this->configureNightwatch();
        $this->configurePulse();
        $this->configureSocialite();
        $this->configureViews();

        // Pennant (database driver): resolved per user and cached per job.
        Feature::define('digest-v2', fn (User $user) => $user->id % 2 === 0);
        Feature::define('feed-rail', fn (User $user) => $user->id % 3 !== 0);

        Gate::define('viewPulse', fn (User $user) => $user->id === 1);
        Gate::define('viewLogViewer', fn (User $user) => $user->id === 1);
    }

    /** Queries are not captured for artisan commands, noisy jobs are sampled down. */
    private function configureNightwatch(): void
    {
        $command = implode(' ', $_SERVER['argv'] ?? []);

        if (str_contains($command, 'artisan') && ! str_contains($command, 'octane:')) {
            Nightwatch::rejectQueries(fn () => true);
        }

        Event::listen(JobProcessing::class, function (JobProcessing $event) {
            if (str_ends_with($event->job->resolveName(), 'CacheWork')) {
                Nightwatch::sample(rate: 0.1);
            }
        });

        Nightwatch::rejectCacheKeys(['/^soak:item:/']);

        Event::listen(function (DiagnosingHealth $event) {
            Nightwatch::dontSample();
        });

        Event::listen(function (CommandStarting $event) {
            if ($event->command === 'horizon:snapshot') {
                Nightwatch::dontSample();
            }
        });
    }

    private function configurePulse(): void
    {
        Pulse::user(fn ($user) => ['name' => $user->name, 'extra' => $user->email]);

        Pulse::filter(function (Entry|Value $entry) {
            $user = Auth::user();

            return $user === null || $user->id !== 1;
        });
    }

    private function configureSocialite(): void
    {
        Event::listen(SocialiteWasCalled::class, DiscordExtendSocialite::class.'@handle');
        Event::listen(SocialiteWasCalled::class, TwitchExtendSocialite::class.'@handle');
    }

    /** Livewire components and Blaze. */
    private function configureViews(): void
    {
        Livewire::component('post-feed', PostFeed::class);

        Blaze::optimize()
            ->in(resource_path('views/components/post'))
            ->in(resource_path('views/components/icon'), memo: true);
    }
}
