# Test environment

Two identical Laravel 13 + Horizon stacks built from `workbench/`, side by side. The workbench carries
a production-sized set of dependencies (Filament, Livewire + Flux + Blaze, Fortify,
Nightwatch, Octane, Scout, laravel/ai, Pulse, Pennant, Socialite, activitylog, health, intervention/image,
AWS SDK, ...; see "Workbench" below), so a process's memory looks like a real app's.

| service | what |
|---|---|
| `app-stock` | Horizon under supervisord, `QUEUE_PREFORK=false`; DB `hp_stock`, Redis DBs 0/1, prefixes `hp_stock*` |
| `app-prefork` | the same, `QUEUE_PREFORK=true`; DB `hp_prefork`, Redis DBs 2/3, prefixes `hp_prefork*` |
| `mysql` | mysql:8.4, small (64 MB buffer pool, no performance_schema) |
| `redis` | redis:7-alpine, no persistence |
| `stub` | `php -S` serving `stub/index.php`: JSON API calls, RSS feeds and item content for the import jobs, an OpenAI-style chat completion endpoint (1.5–4 s), web-push endpoints |
| `setup` | one-shot `composer install` of `workbench/vendor` inside Linux; the app services wait for it |

Each app container: tini (PID 1) → supervisord → `php /repo/workbench/<entry> horizon`, configured as a
Forge daemon (`stopwaitsecs=5`, `killasgroup=true`, `autorestart=true`). With `HORIZON=false
QUEUE_WORK=true` supervisord runs `QUEUE_WORK_PROCS` plain `php artisan queue:work redis` programs
instead (`queue-work_00`, ...), each logging to its own `/tmp/queue-work-NN.{out,err}`; in app-prefork
each program's pid is then a stub whose worker the shared template forked. Everything runs as `forge`
(uid 1000). The repo is mounted at `/repo`; `workbench/storage` and `workbench/bootstrap/cache` are
named volumes per container, so the two stacks never share logs, compiled views or cached config.
Memory limit 2.6 GB per app container (its own cgroup), with swap off for it (`memswap_limit`), so
memory is never paged out mid-measurement.

Everything except `setup` sits on an internal Docker network with no route out: the app's packages
can reach only MySQL, Redis and the stub, so a misconfigured endpoint fails instead of calling the
internet. `setup` (Composer) is on the default network.

At start the entrypoint runs `package:discover`, `migrate --force`, `db:seed` (50 users with web-push
subscriptions; idempotent), then `optimize` and `filament:optimize` (config, events, routes, views,
Filament components and icons cached, as a production deploy), or `optimize:clear` and
`filament:optimize-clear` with `CONFIG_CACHE=false`.

## Commands

Run from `docker/`. `docker compose exec` runs as `forge` already.

```sh
docker compose build                                  # the PHP 8.4 image (pcntl, opcache, pdo_mysql, ffi, sockets, redis, intl, gd, zip, bcmath, gmp, exif, ftp)
docker compose run --rm setup                         # composer install (also runs on every `up`)
docker compose run --rm setup /repo/docker/php/setup.sh update   # composer update

docker compose up -d app-stock                        # one stack (starts mysql, redis, stub, setup)
docker compose up -d app-stock app-prefork            # both
docker compose logs -f app-stock                      # Horizon output, job lines, dump()/STDERR
docker compose exec app-stock php artisan horizon:status
docker compose exec app-stock php artisan horizon:supervisors
docker compose exec app-stock ps -eo pid,ppid,rss,args --forest
docker compose exec app-stock supervisorctl -c /repo/docker/php/supervisord.conf status

docker compose down                                   # stop and remove containers, keep volumes
docker compose down -v                                # also drop MySQL data and the per-container volumes
```

After changing an env switch, recreate: `docker compose up -d --force-recreate app-stock`.

## Fixtures

```sh
docker compose exec app-stock php artisan fixtures:dispatch --list
docker compose exec app-stock php artisan fixtures:dispatch                 # all but crashes
docker compose exec app-stock php artisan fixtures:dispatch crashes         # exit(255); --crash=segv for SIGSEGV
docker compose exec app-stock php artisan fixtures:dispatch sleeps --sleep=20 --queue=import
docker compose exec app-stock php artisan fixtures:dispatch sleeps-steady --sleep=20   # sleeps through signals
docker compose exec app-stock php artisan fixtures:results                  # job_results table, failed_jobs count
docker compose exec app-stock php artisan fixtures:results --job=ReadsArgv --json
docker compose exec app-stock php artisan fixtures:results --reset          # truncate job_results, failed_jobs, job_batches
```

Every fixture writes `job_results` rows (`id, job, pid, argv, value, created_at`). A crashed job stays
reserved until `retry_after` (180 s) and is then failed. Every Horizon process also appends one line
per `CommandStarting` to `storage/logs/lifecycle.log` (pid, ppid, argv, application class);
`WORKBENCH_TRACE=false` turns that off.

## Integration tests

From the repo root, with both stacks up:

```sh
HP_INTEGRATION=1 vendor/bin/phpunit --testsuite Integration    # Horizon's tests run; QueueWorkParityTest skips
HORIZON_HOST=false docker compose -f docker/compose.yml up -d --force-recreate app-prefork   # the master spawns its template (fallback mode)
HP_INTEGRATION=1 vendor/bin/phpunit tests/Integration/HorizonPreforkTest.php tests/Integration/LifecycleParityTest.php
docker compose -f docker/compose.yml up -d --force-recreate app-prefork                     # back to host mode
HORIZON=false QUEUE_WORK=true docker compose -f docker/compose.yml up -d --force-recreate app-stock app-prefork
HP_INTEGRATION=1 vendor/bin/phpunit tests/Integration/QueueWorkParityTest.php
```

`QueueWorkParityTest` does each operation (queue:restart, supervisorctl stop/signal, timeouts, crashes,
maintenance mode) to both stacks with jobs in flight and requires prefork to match stock, down to
supervisord's exit lines in `docker compose logs`.

## Gates

```sh
sh docker/systemd/run.sh   # systemd units (control-group and mixed stops, journald, a killed worker, three workers in one unit, a boot crash), each against a stock unit
docker run --rm --init --network none -u 1000:1000 -v "$PWD:/repo:ro" queue-prefork-php:8.4 sh /repo/docker/supervisord/gate.sh   # a numprocs=1 program whose forked worker crashes at boot runs stock, not FATAL
docker build --platform linux/amd64 -t queue-prefork-amd64 docker/amd64
docker run --rm --init --platform linux/amd64 --network none -u 1000:1000 -v "$PWD:/repo" -w /repo queue-prefork-amd64 vendor/bin/phpunit --testsuite Unit
```

Under amd64 emulation (QEMU) `/proc/<pid>/exe` is the emulator and every command line starts with it, so
the template rightly refuses every peer: only the Unit suite (the FFI layouts) is meaningful there. The
Mechanics suite needs a real x86_64 kernel.

## Soak

```sh
../soak/run.sh 8                         # both stacks, 8 h, same stream, then report
SOAK_RATE=420 SOAK_SEED=1 ../soak/run.sh 0.25   # 15-minute smoke run
HORIZON=false QUEUE_WORK=true QUEUE_WORK_PROCS=8 SOAK_MODE_STOCK=stock-qw SOAK_MODE_PREFORK=prefork-qw ../soak/run.sh 2   # queue:work
SOAK_MIX=light SOAK_MODE_STOCK=stock-light SOAK_MODE_PREFORK=prefork-light ../soak/run.sh 1   # the light mix
docker run --rm --network none -u 1000:1000 -v "$PWD/..:/repo" queue-prefork-php:8.4 php /repo/soak/report.php   # re-render the report
```

`run.sh` recreates both app containers with `SOAK_DISPATCH=true SOAK_SAMPLE=true`, so each runs
`soak:dispatch` (same seed) and `soak/sample.php` as supervisord programs; results land in
`soak/results/<mode>/{processes,cgroup}.csv`. To run the pieces by hand:

```sh
docker compose exec app-stock php artisan soak:dispatch --rate=420 --duration=10 --seed=1
docker compose exec app-stock php artisan soak:dispatch --dry-run --duration=60   # the mix, no dispatch
docker compose exec app-stock php /repo/soak/sample.php --once                   # one sample
```

## Switches

Host environment, read when a container is created (`up -d --force-recreate`):

| variable | default | effect |
|---|---|---|
| `HORIZON` | `true` | run the `horizon` program |
| `QUEUE_WORK` / `QUEUE_WORK_PROCS` | `false` / `4` | run that many `queue:work` programs |
| `QUEUE_WORK_QUEUES` | `import,media,default,ai,scout,notify,maintenance,digest` | their `--queue` |
| `QUEUE_WORK_STOPWAIT` | `10` | their supervisord `stopwaitsecs` |
| `SUPERVISOR_STOPASGROUP` | `true` | supervisord `stopasgroup` (`killasgroup` is always `true`) |
| `HORIZON_FAST_TERMINATION` | `true` | `horizon.fast_termination` |
| `CONFIG_CACHE` | `true` | `optimize` + `filament:optimize` at start; `false` runs uncached |
| `NIGHTWATCH_ENABLED` / `PULSE_ENABLED` | `true` / `true` | switch the two monitoring packages off, e.g. to measure their cost |
| `SOAK_DISPATCH` / `SOAK_RATE` / `SOAK_DURATION` / `SOAK_SEED` | `false` / `420` / `60` / `1` | the `soak-dispatch` program (jobs/min, minutes, seed) |
| `SOAK_MIX` | `heavy` | the job mix: `heavy` (below) or `light` |
| `HORIZON_HOST` | `true` | prefork stack: the Horizon master forked from its host template; `false` has it spawn its template (fallback mode) |
| `SOAK_SAMPLE` / `SOAK_SAMPLE_INTERVAL` | `false` / `60` | the `soak-sample` program (seconds) |
| `SOAK_MODE_STOCK` / `SOAK_MODE_PREFORK` | `stock` / `prefork` | result directory names under `soak/results/` |

`QUEUE_PREFORK` is fixed per service (`false` in app-stock, `true` in app-prefork).

Variants, as extra compose files:

```sh
docker compose -f compose.yml -f compose.prepend.yml up -d     # auto_prepend_file = workbench/prepend.php
docker compose -f compose.yml -f compose.pid1.yml up -d        # no tini/supervisord: the master is PID 1
docker compose -f compose.yml -f compose.subreaper.yml up -d   # docker-init PID 1, tini -s as a child subreaper
```

Not provided: a low-`pid_max` variant. On this Docker Desktop kernel (6.10) `kernel.pid_max` is
global to the VM, so setting it from a privileged container would affect every other container.

## Workbench

`workbench/composer.json` requires, at production-style versions (Laravel 13 / PHP 8.4):

- filament/filament 5, livewire/livewire 4, livewire/flux, livewire/blaze, laravel/fortify,
  laravel/nightwatch, laravel/octane, laravel/scout 11 + typesense/typesense-php, laravel/ai,
  opcodesio/log-viewer, spatie/laravel-health, spatie/laravel-sluggable, intervention/image 4,
  minishlink/web-push, mews/purifier, imangazaliev/didom, laminas/laminas-feed, maxmind-db/reader,
  ramsey/uuid, league/flysystem-aws-s3-v3, aws/aws-sdk-php, laravel/pulse, laravel/pennant, laravel/socialite + socialiteproviders
  (discord, twitch), spatie/laravel-activitylog, spatie/laravel-sitemap,
  spatie/schema-org, spatie/valuestore, symfony/html-sanitizer, itsgoingd/clockwork,
  cviebrock/eloquent-sluggable, geoip2/geoip2, bepsvpt/blurhash, webklex/php-imap,
  league/flysystem-ftp, platformcommunity/flysystem-bunnycdn.
- No dev dependencies (production installs without them).

What boots, offline:

- a Filament admin panel (`/admin`) with Source, Post, MediaAsset and User resources, database
  notifications and links to Horizon, Pulse and Log Viewer;
- a Livewire component (`post-feed`) built from Flux components and Blaze-compiled Blade components;
- Fortify (with passkeys), Socialite with two providers (dummy credentials), Pennant
  (database driver), Pulse (database storage), spatie/laravel-health checks;
- Nightwatch with a dummy token and no agent: after every job it tries `127.0.0.1:2407`, gets
  "connection refused" at once, and drops the batch silently (Nightwatch's unrecoverable-exception
  hook has no handler by default). Jobs neither slow down nor fail;
- Scout with the `database` engine; index writes are queued on the `scout` queue;
- laravel/ai (OpenRouter driver) and S3 point at the stub; web push uses test VAPID keys and stub
  endpoints.

The soak's jobs (`soak:dispatch`) use those packages:

| queue | job | what it does |
|---|---|---|
| import | ImportWork | stub RSS feed (laminas-feed), item content from the stub's content API read with DiDom, bodies through mews/purifier, excerpts through symfony/html-sanitizer; posts upserted (cviebrock slugs, activity log), one Scout index job per import |
| media | ImageWork | intervention/image: draw, JPEG round trip, two renditions (WebP, JPEG), blurhash; 500 file names reused |
| ai | LlmWork | a laravel/ai structured completion (stub, 1.5–4 s), then the post's update (activity log, Scout job) |
| scout | ScoutWork | Scout searches over posts and sources (database engine), cached 30 s; plus the import jobs' index jobs |
| default | FeedRenderWork, EloquentWork | Livewire + Flux + Blaze feed render and schema.org JSON-LD; bulk upserts |
| notify | NotifyWork, CacheWork | notifications via database, the log mailer (`storage/logs/mail-*.log`) and web push |
| maintenance | MaintenanceWork | bulk upsert, a 2000-URL sitemap (spatie/laravel-sitemap), a valuestore file |
| digest | DigestWork (bursts) | Pennant check, markdown digest mail render, cached |

`SOAK_MIX=light` is a typical light app's job stream on the same app, with no bursts and none of the
heavy jobs (image renditions, LLM calls, Livewire renders, bulk maintenance):

| queue | job | weight | what it does |
|---|---|---:|---|
| notify | NotifyWork | 40 | 1–2 notifications: database, log mailer, web push to the stub |
| default | EloquentWork | 30 | upserts 5–20 rows |
| notify | CacheWork | 20 | 10–20 cache reads |
| import | ImportWork | 10 | one short HTTP call to the stub (the feed), parsed and upserted |

## Notes

- The image keeps `php.ini-production` with `opcache.enable_cli` at its default (off), as on a stock
  Forge server. One override, `php/conf.d/zz-cli.ini`: `memory_limit=-1`, as Ubuntu's CLI php.ini has.
  Otherwise a worker would die at 128M before Horizon's own `memory` check (128 MB) recycles it.
- `workbench/composer.json` pins `laravel/horizon` to `5.50.*`.
- The package is a Composer path repository (`..`, symlinked), so changes to `src/` need no reinstall;
  restart Horizon (`docker compose restart app-prefork`) to load them.
