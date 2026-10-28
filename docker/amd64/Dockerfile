# The x86_64 gate (IPC bridge): Unit + Mechanics on linux/amd64, for the FFI struct
# layouts, under emulation on an arm64 host. Only what those suites need.
#
#   docker build --platform linux/amd64 -t queue-prefork-amd64 docker/amd64
#   docker run --rm --init --platform linux/amd64 --network none -u 1000:1000 -v "$PWD:/repo" -w /repo \
#       queue-prefork-amd64 vendor/bin/phpunit --testsuite Unit,Mechanics
FROM php:8.4-cli

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libffi-dev; \
    rm -rf /var/lib/apt/lists/*; \
    docker-php-ext-install -j"$(nproc)" pcntl ffi opcache
