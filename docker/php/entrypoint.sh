#!/bin/sh
# Entrypoint of the app containers (app-stock, app-prefork), run as forge under tini (PID 1).
# Prepares this container's storage and caches, migrates and seeds its database, caches config,
# events, routes, views and Filament components (unless CONFIG_CACHE=false), then execs supervisord,
# which runs Horizon the way Forge does (SUPERVISE=false, used by compose.pid1.yml, execs the
# Horizon master directly instead).
set -eu

: "${HORIZON_HOST:=true}"
: "${SUPERVISOR_STOPASGROUP:=true}"
: "${CONFIG_CACHE:=true}"
: "${SOAK_MODE:=stock}"
: "${SOAK_DISPATCH:=false}"
: "${SOAK_MIX:=heavy}"
: "${SOAK_RATE:=300}"
: "${SOAK_DURATION:=60}"
: "${SOAK_SEED:=1}"
: "${SOAK_SAMPLE:=false}"
: "${SOAK_SAMPLE_INTERVAL:=60}"
: "${SUPERVISE:=true}"
: "${HORIZON:=true}"
: "${QUEUE_WORK:=false}"
: "${QUEUE_WORK_PROCS:=4}"
: "${QUEUE_WORK_QUEUES:=import,media,default,ai,scout,notify,maintenance,digest}"
: "${QUEUE_WORK_STOPWAIT:=10}"
# HORIZON_HOST=false: the master starts as before and spawns its template (QUEUE_PREFORK_HANDOFF=0
# for the Horizon program only).
HORIZON_HANDOFF=$([ "$HORIZON_HOST" = "false" ] && echo 0 || echo 1)
export HORIZON_HANDOFF SUPERVISOR_STOPASGROUP CONFIG_CACHE SOAK_MODE SOAK_DISPATCH SOAK_MIX SOAK_RATE \
    SOAK_DURATION SOAK_SEED SOAK_SAMPLE SOAK_SAMPLE_INTERVAL HORIZON QUEUE_WORK QUEUE_WORK_PROCS \
    QUEUE_WORK_QUEUES QUEUE_WORK_STOPWAIT

app=/repo/workbench
cd "$app"

if [ ! -f vendor/autoload.php ]; then
    echo "entrypoint: $app/vendor is missing; run: docker compose run --rm setup" >&2
    exit 1
fi

# storage/ and bootstrap/cache are named volumes of this container (see compose.yml).
mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
    storage/framework/sessions storage/framework/views storage/framework/testing \
    storage/logs bootstrap/cache "/repo/soak/results/$SOAK_MODE"

# Stale caches from a previous start of this container (the package and services manifests, the
# config) are rebuilt from scratch.
rm -f bootstrap/cache/*.php

php artisan package:discover --no-ansi >/dev/null
php artisan migrate --force --no-interaction --no-ansi
php artisan db:seed --force --no-interaction --no-ansi

# A production deploy's caches: config, events, routes and compiled views (`optimize`), and Filament's
# component and icon caches. CONFIG_CACHE=false runs everything uncached.
if [ "$CONFIG_CACHE" = "true" ]; then
    php artisan optimize --no-ansi
    php artisan filament:optimize --no-ansi
else
    php artisan optimize:clear --no-ansi
    php artisan filament:optimize-clear --no-ansi
fi

if [ "$SUPERVISE" = "false" ]; then
    # compose.pid1.yml: no supervisord, the master replaces this shell (PID 1 without tini).
    QUEUE_PREFORK_HANDOFF=$HORIZON_HANDOFF exec php /repo/workbench/artisan horizon
fi

exec /usr/bin/supervisord -n -c /repo/docker/php/supervisord.conf
