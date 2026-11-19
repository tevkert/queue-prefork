#!/bin/sh
# One-shot `setup` service: installs the workbench's vendor/ inside Linux, so platform checks
# (ext-pcntl, ext-posix, ext-redis) match the app containers.
set -eu
cd /repo/workbench

if [ "${1:-}" = "update" ]; then
    composer update --no-interaction --no-progress
else
    composer install --no-interaction --no-progress
fi

# The package goes in as a copy, as Composer installs it from Packagist, not as the path repository's
# symlink to the repo root: PHP resolves symlinks in stack traces, and error trackers judge a frame by
# its path (App\Support\ErrorTrackers). Copied again on every run, so edits to src/ reach the stacks
# when they are recreated.
pkg=vendor/tevkert/queue-prefork
rm -rf "$pkg.copy"
mkdir "$pkg.copy"
cp -R /repo/bin /repo/config /repo/src /repo/composer.json "$pkg.copy/"

if [ -L "$pkg" ]; then
    rm "$pkg"
else
    rm -rf "$pkg"
fi

mv "$pkg.copy" "$pkg"
