# PHP 8.4 CLI image for the queue-prefork test environment.
#
# One image serves every service that runs PHP: the two app stacks (stock and prefork), the
# one-shot `setup` (Composer) service and the HTTP stub. The repo is bind-mounted at /repo;
# nothing from the repo is baked in.
FROM php:8.4-cli-bookworm

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        procps supervisor tini git unzip curl libffi-dev \
        libicu-dev libzip-dev libgmp-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev; \
    rm -rf /var/lib/apt/lists/*

# posix, ctype, mbstring, tokenizer, curl, sodium, fileinfo ... are compiled into the base image already.
RUN set -eux; \
    docker-php-ext-install -j"$(nproc)" pcntl opcache pdo_mysql ffi sockets; \
    pecl install redis; \
    docker-php-ext-enable redis; \
    rm -rf /tmp/pear ~/.pearrc

# What the workbench's dependency set needs: intl (Filament), gd with JPEG/WebP/FreeType
# (intervention/image, bepsvpt/blurhash), zip (openspout, php-imap), bcmath and gmp (web-push's JWT
# and EC math), exif (image orientation), ftp (league/flysystem-ftp).
RUN set -eux; \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp; \
    docker-php-ext-install -j"$(nproc)" intl gd zip bcmath gmp exif ftp

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# A stock production ini. opcache.enable_cli stays at its default (off), as on a Forge server:
# that is what makes stock Horizon workers compile the whole framework per process. The one override
# (conf.d/zz-cli.ini) is what Ubuntu's CLI php.ini differs in: memory_limit=-1.
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY conf.d/zz-cli.ini $PHP_INI_DIR/conf.d/zz-cli.ini

# An unprivileged daemon user, like Forge's `forge`.
RUN set -eux; \
    groupadd --gid 1000 forge; \
    useradd --uid 1000 --gid forge --create-home --shell /bin/bash forge

# The app containers run as forge throughout (compose `user: 1000:1000`). The per-container named
# volumes over storage/ and bootstrap/cache are initialised from these forge-owned directories.
RUN set -eux; \
    mkdir -p /repo/workbench/storage /repo/workbench/bootstrap/cache /home/forge/.composer/cache; \
    chown -R forge:forge /repo /home/forge

ENV COMPOSER_HOME=/home/forge/.composer

WORKDIR /repo/workbench
