<?php

declare(strict_types=1);

namespace Pulsar\Console\Command\Dev;

use Pulsar\Api\Internal;

use function array_merge;
use function array_unique;
use function count;
use function implode;
use function in_array;
use function sprintf;

/**
 * Generates Dockerfile content for the Pulsar development environment.
 */
#[Internal]
final readonly class DockerfileGenerator
{
    /** @var list<string> Required PHP extensions for Pulsar */
    private const array REQUIRED_EXTENSIONS = [
        'sodium',
        'pcntl',
        'sockets',
        'pdo_sqlite',
        'gd',
        'intl',
        'zip',
        'bcmath',
        'mbstring',
    ];

    public function __construct(
        private DevConfig $config,
    ) {}

    /**
     * Generate the Dockerfile content.
     */
    public function generate(): string
    {
        $extensions = $this->resolveExtensions();
        $extensionInstalls = $this->buildExtensionInstalls($extensions);

        $lines = [
            sprintf('FROM php:%s-cli-alpine', $this->config->phpVersion),
            '',
            'ARG PHP_VERSION=8.5',
            'ARG MEMORY_LIMIT=512M',
            'ARG XDEBUG=1',
            '',
            '# System dependencies',
            'RUN apk add --no-cache \\',
            '    git \\',
            '    curl \\',
            '    libpng-dev \\',
            '    libjpeg-turbo-dev \\',
            '    freetype-dev \\',
            '    libzip-dev \\',
            '    icu-dev \\',
            '    libsodium-dev \\',
            '    oniguruma-dev \\',
            '    linux-headers \\',
            '    postgresql-dev \\',
            '    nodejs \\',
            '    npm',
            '',
            '# Install pnpm',
            'RUN npm install -g pnpm',
            '',
        ];

        if ($extensionInstalls !== '') {
            $lines[] = '# PHP extensions';
            $lines[] = $extensionInstalls;
            $lines[] = '';
        }

        if ($this->config->xdebug) {
            $lines[] = '# Xdebug (conditional)';
            $lines[] = 'RUN if [ "$XDEBUG" = "1" ]; then \\';
            $lines[] = '    apk add --no-cache $PHPIZE_DEPS && \\';
            $lines[] = '    pecl install xdebug && \\';
            $lines[] = '    docker-php-ext-enable xdebug && \\';
            $lines[] = '    echo "xdebug.mode=debug,coverage" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini && \\';
            $lines[] = '    echo "xdebug.client_host=host.docker.internal" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini && \\';
            $lines[] = '    echo "xdebug.start_with_request=trigger" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini; \\';
            $lines[] = '    fi';
            $lines[] = '';
        }

        $lines[] = '# OPcache configuration for development';
        $lines[] = 'RUN echo "opcache.enable=1" >> /usr/local/etc/php/conf.d/opcache.ini && \\';
        $lines[] = '    echo "opcache.enable_cli=0" >> /usr/local/etc/php/conf.d/opcache.ini && \\';
        $lines[] = '    echo "opcache.validate_timestamps=1" >> /usr/local/etc/php/conf.d/opcache.ini && \\';
        $lines[] = '    echo "opcache.revalidate_freq=0" >> /usr/local/etc/php/conf.d/opcache.ini';
        $lines[] = '';

        $lines[] = sprintf(
            '# PHP memory limit',
        );
        $lines[] = 'RUN echo "memory_limit=${MEMORY_LIMIT}" > /usr/local/etc/php/conf.d/memory.ini';
        $lines[] = '';

        $lines[] = '# Install Composer';
        $lines[] = 'COPY --from=composer:2 /usr/bin/composer /usr/bin/composer';
        $lines[] = '';

        $lines[] = 'WORKDIR /app';
        $lines[] = '';

        $lines[] = '# Install dependencies (cached layer)';
        $lines[] = 'COPY composer.json composer.lock* ./';
        $lines[] = 'RUN composer install --no-scripts --no-autoloader --prefer-dist';
        $lines[] = '';

        $lines[] = '# Copy application';
        $lines[] = 'COPY . .';
        $lines[] = 'RUN composer dump-autoload --optimize';
        $lines[] = '';

        $lines[] = 'EXPOSE 8080';
        $lines[] = '';
        $lines[] = 'CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function resolveExtensions(): array
    {
        $extensions = self::REQUIRED_EXTENSIONS;

        if ($this->config->database === 'pgsql') {
            $extensions[] = 'pdo_pgsql';
        }

        if ($this->config->database === 'mysql') {
            $extensions[] = 'pdo_mysql';
        }

        if ($this->config->redis) {
            $extensions[] = 'redis';
        }

        $extensions = array_merge($extensions, $this->config->phpExtensions);

        return array_values(array_unique($extensions));
    }

    /**
     * @param list<string> $extensions
     */
    private function buildExtensionInstalls(array $extensions): string
    {
        // Separate PECL vs docker-php-ext-install
        $peclExtensions = ['redis', 'xdebug'];
        $configureSteps = [];
        $installExtensions = [];
        $peclInstall = [];

        foreach ($extensions as $ext) {
            if (in_array($ext, $peclExtensions, true)) {
                if ($ext !== 'xdebug') { // xdebug handled separately
                    $peclInstall[] = $ext;
                }
            } else {
                if ($ext === 'gd') {
                    $configureSteps[] = 'docker-php-ext-configure gd --with-freetype --with-jpeg';
                }

                $installExtensions[] = $ext;
            }
        }

        $lines = [];

        foreach ($configureSteps as $step) {
            $lines[] = sprintf('RUN %s', $step);
        }

        if ($installExtensions !== []) {
            $lines[] = sprintf('RUN docker-php-ext-install -j$(nproc) %s', implode(' ', $installExtensions));
        }

        if ($peclInstall !== []) {
            $lines[] = 'RUN apk add --no-cache $PHPIZE_DEPS \\';
            $lines[] = sprintf('    && pecl install %s \\', implode(' ', $peclInstall));

            foreach ($peclInstall as $ext) {
                $lines[] = sprintf('    && docker-php-ext-enable %s \\', $ext);
            }

            // Remove trailing backslash from last line
            $lastIndex = count($lines) - 1;
            $lines[$lastIndex] = rtrim($lines[$lastIndex], ' \\');
        }

        return implode("\n", $lines);
    }
}
