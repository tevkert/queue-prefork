#!/bin/sh
# One-shot `setup` service: installs the workbench's vendor/ inside Linux, so platform checks
# (ext-pcntl, ext-posix, ext-redis) match the app containers. The package itself is a symlink to
# the repo root (Composer path repository), so edits to src/ need no reinstall.
set -eu
cd /repo/workbench

if [ "${1:-}" = "update" ]; then
    composer update --no-interaction --no-progress
else
    composer install --no-interaction --no-progress
fi
