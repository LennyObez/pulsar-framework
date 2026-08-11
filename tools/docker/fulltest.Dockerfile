# Full-extension PHP 8.5 image to run the COMPLETE PHPUnit suite with ZERO skips.
#
# It provisions every PHP extension and service the suite's environment-gated
# tests require (redis, memcached, apcu, imagick, msgpack, gnupg, shmop, intl,
# sodium, gd-with-webp, pdo_*, pcntl, posix) plus the Redis/Memcached servers
# and protoc binary — none of which exist on the Windows dev host.
#
# Build (from repo root):
#   docker build -f tools/docker/fulltest.Dockerfile -t pulsar-fulltest .
# Run:
#   docker run --rm pulsar-fulltest
FROM php:8.5-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip ca-certificates \
        libicu-dev libzip-dev libpq-dev libsqlite3-dev \
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev \
        libmagickwand-dev imagemagick \
        libmemcached-dev zlib1g-dev \
        libgpgme-dev \
        librabbitmq-dev \
        libsodium-dev \
        redis-server memcached \
        protobuf-compiler \
    && rm -rf /var/lib/apt/lists/*

# Bundled extensions (gd built with JPEG/FreeType/WebP so the WebP tests run).
# posix and opcache are already provided by the base php:8.5-cli image.
RUN docker-php-ext-configure gd --with-jpeg --with-freetype --with-webp \
    && docker-php-ext-install \
        intl sodium gd zip exif pdo_mysql pdo_pgsql pdo_sqlite shmop sockets pcntl

# PECL extensions compiled against PHP 8.5.
RUN pecl install redis apcu msgpack imagick memcached gnupg amqp \
    && docker-php-ext-enable redis apcu msgpack imagick memcached gnupg amqp

# APCu must be active under CLI for the cache/lock tests.
RUN { echo 'apc.enabled=1'; echo 'apc.enable_cli=1'; } > /usr/local/etc/php/conf.d/zz-apcu-cli.ini \
    && echo 'opcache.enable_cli=1' > /usr/local/etc/php/conf.d/zz-opcache-cli.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Create the non-root user before COPY so ownership is set during the copy
# (a recursive chown of /app would be a huge, slow extra layer). Redis/Memcached
# run on loopback high ports with state under /tmp, so root is never needed at runtime.
RUN useradd --create-home --uid 1000 pulsar && mkdir -p /app && chown pulsar:pulsar /app
WORKDIR /app
COPY --chown=pulsar:pulsar . /app

# `--chown` settles ownership, not mode: COPY carries the build context's permissions
# through, and a context exported from a filesystem without POSIX modes — a Windows
# checkout, more so one inside OneDrive — hands `var/` over as 0555. The directory is
# then read-only to its own owner, every boot test writing a fixture under it fails with
# `mkdir(): Permission denied`, and the kernel answers 500 to everything because its
# configuration was never written. An image must not inherit that from whoever built it.
RUN mkdir -p /app/var && chmod -R u+rwX /app/var

USER pulsar

RUN composer install --no-interaction --no-progress --prefer-dist

CMD ["bash", "tools/docker/run-full-suite.sh"]
