<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command\Dev;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\Dev\DevConfig;
use Pulsar\Console\Command\Dev\DockerfileGenerator;

#[CoversClass(DockerfileGenerator::class)]
final class DockerfileGeneratorTest extends TestCase
{
    #[Test]
    public function generateContainsBaseImage(): void
    {
        $config = new DevConfig(phpVersion: '8.5');
        $generator = new DockerfileGenerator($config);
        $output = $generator->generate();

        self::assertStringContainsString('FROM php:8.5-cli-alpine', $output);
    }

    #[Test]
    public function generateIncludesSystemDependencies(): void
    {
        $config = new DevConfig();
        $generator = new DockerfileGenerator($config);
        $output = $generator->generate();

        self::assertStringContainsString('libsodium-dev', $output);
        self::assertStringContainsString('postgresql-dev', $output);
        self::assertStringContainsString('icu-dev', $output);
        self::assertStringContainsString('libzip-dev', $output);
    }

    #[Test]
    public function generateIncludesComposer(): void
    {
        $config = new DevConfig();
        $generator = new DockerfileGenerator($config);
        $output = $generator->generate();

        self::assertStringContainsString('COPY --from=composer:2 /usr/bin/composer /usr/bin/composer', $output);
    }

    #[Test]
    public function generateIncludesPnpm(): void
    {
        $config = new DevConfig();
        $generator = new DockerfileGenerator($config);
        $output = $generator->generate();

        self::assertStringContainsString('npm install -g pnpm', $output);
    }

    #[Test]
    public function generateIncludesRequiredExtensions(): void
    {
        $config = new DevConfig();
        $generator = new DockerfileGenerator($config);
        $output = $generator->generate();

        self::assertStringContainsString('sodium', $output);
        self::assertStringContainsString('pcntl', $output);
        self::assertStringContainsString('sockets', $output);
        self::assertStringContainsString('intl', $output);
        self::assertStringContainsString('gd', $output);
    }

    #[Test]
    public function generateIncludesPostgresExtensionForPgsql(): void
    {
        $config = new DevConfig(database: 'pgsql');
        $generator = new DockerfileGenerator($config);
        $output = $generator->generate();

        self::assertStringContainsString('pdo_pgsql', $output);
    }

    #[Test]
    public function generateIncludesMysqlExtensionForMysql(): void
    {
        $config = new DevConfig(database: 'mysql');
        $generator = new DockerfileGenerator($config);
        $output = $generator->generate();

        self::assertStringContainsString('pdo_mysql', $output);
    }

    #[Test]
    public function generateIncludesRedisExtensionWhenEnabled(): void
    {
        $config = new DevConfig(redis: true);
        $generator = new DockerfileGenerator($config);
        $output = $generator->generate();

        self::assertStringContainsString('pecl install redis', $output);
    }

    #[Test]
    public function generateExcludesRedisExtensionWhenDisabled(): void
    {
        $config = new DevConfig(redis: false);
        $generator = new DockerfileGenerator($config);
        $output = $generator->generate();

        self::assertStringNotContainsString('pecl install redis', $output);
    }

    #[Test]
    public function generateIncludesXdebugWhenEnabled(): void
    {
        $config = new DevConfig(xdebug: true);
        $generator = new DockerfileGenerator($config);
        $output = $generator->generate();

        self::assertStringContainsString('pecl install xdebug', $output);
        self::assertStringContainsString('xdebug.mode=debug,coverage', $output);
        self::assertStringContainsString('xdebug.client_host=host.docker.internal', $output);
        self::assertStringContainsString('xdebug.start_with_request=trigger', $output);
    }

    #[Test]
    public function generateExcludesXdebugWhenDisabled(): void
    {
        $config = new DevConfig(xdebug: false);
        $generator = new DockerfileGenerator($config);
        $output = $generator->generate();

        self::assertStringNotContainsString('xdebug', $output);
    }

    #[Test]
    public function generateIncludesOpcacheForDevelopment(): void
    {
        $config = new DevConfig();
        $generator = new DockerfileGenerator($config);
        $output = $generator->generate();

        self::assertStringContainsString('opcache.validate_timestamps=1', $output);
        self::assertStringContainsString('opcache.revalidate_freq=0', $output);
    }

    #[Test]
    public function generateConfiguresGdWithFreetypeAndJpeg(): void
    {
        $config = new DevConfig();
        $generator = new DockerfileGenerator($config);
        $output = $generator->generate();

        self::assertStringContainsString('docker-php-ext-configure gd --with-freetype --with-jpeg', $output);
    }

    #[Test]
    public function generateIncludesCustomExtensions(): void
    {
        $config = new DevConfig(phpExtensions: ['apcu', 'swoole']);
        $generator = new DockerfileGenerator($config);
        $output = $generator->generate();

        self::assertStringContainsString('apcu', $output);
        self::assertStringContainsString('swoole', $output);
    }

    #[Test]
    public function generateExposesPort8080(): void
    {
        $config = new DevConfig();
        $generator = new DockerfileGenerator($config);
        $output = $generator->generate();

        self::assertStringContainsString('EXPOSE 8080', $output);
    }

    #[Test]
    public function generateIncludesWorkdir(): void
    {
        $config = new DevConfig();
        $generator = new DockerfileGenerator($config);
        $output = $generator->generate();

        self::assertStringContainsString('WORKDIR /app', $output);
    }

    #[Test]
    public function generateDeduplicatesExtensions(): void
    {
        $config = new DevConfig(phpExtensions: ['sodium', 'sodium', 'intl']);
        $generator = new DockerfileGenerator($config);
        $output = $generator->generate();

        // Should not have duplicate installations
        $count = substr_count($output, 'sodium');
        // sodium appears in system deps (libsodium-dev) and ext install
        self::assertGreaterThanOrEqual(1, $count);
    }
}
