<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extensibility\Manifest;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extensibility\Exception\ManifestException;
use Pulsar\Extensibility\Manifest\ProvidesConfig;

#[CoversClass(ProvidesConfig::class)]
final class ProvidesConfigTest extends TestCase
{
    #[Test]
    public function constructorDefaults(): void
    {
        $config = new ProvidesConfig();

        self::assertSame([], $config->services);
        self::assertSame([], $config->commands);
        self::assertFalse($config->routes);
        self::assertSame([], $config->middleware);
        self::assertSame([], $config->migrations);
    }

    #[Test]
    public function fromArrayWithAllFields(): void
    {
        $config = ProvidesConfig::fromArray([
            'services' => ['App\\Service\\FooService'],
            'commands' => ['App\\Command\\BarCommand'],
            'routes' => true,
            'middleware' => ['App\\Middleware\\AuthMiddleware'],
        ]);

        self::assertSame(['App\\Service\\FooService'], $config->services);
        self::assertSame(['App\\Command\\BarCommand'], $config->commands);
        self::assertTrue($config->routes);
        self::assertSame(['App\\Middleware\\AuthMiddleware'], $config->middleware);
    }

    #[Test]
    public function fromArrayWithDefaults(): void
    {
        $config = ProvidesConfig::fromArray([]);

        self::assertSame([], $config->services);
        self::assertSame([], $config->commands);
        self::assertFalse($config->routes);
        self::assertSame([], $config->middleware);
        self::assertSame([], $config->migrations);
    }

    #[Test]
    public function fromArrayThrowsOnAssociativeServices(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessage('provides.services');

        $_ = ProvidesConfig::fromArray([
            'services' => ['key' => 'value'],
        ]);
    }

    #[Test]
    public function fromArrayThrowsOnAssociativeCommands(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessage('provides.commands');

        $_ = ProvidesConfig::fromArray([
            'commands' => ['key' => 'value'],
        ]);
    }

    #[Test]
    public function fromArrayThrowsOnAssociativeMiddleware(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessage('provides.middleware');

        $_ = ProvidesConfig::fromArray([
            'middleware' => ['key' => 'value'],
        ]);
    }

    #[Test]
    public function hasServicesReturnsTrueWhenNonEmpty(): void
    {
        $config = new ProvidesConfig(services: ['SomeService']);

        self::assertTrue($config->hasServices());
    }

    #[Test]
    public function hasServicesReturnsFalseWhenEmpty(): void
    {
        $config = new ProvidesConfig();

        self::assertFalse($config->hasServices());
    }

    #[Test]
    public function hasCommandsReturnsTrueWhenNonEmpty(): void
    {
        $config = new ProvidesConfig(commands: ['SomeCommand']);

        self::assertTrue($config->hasCommands());
    }

    #[Test]
    public function hasRoutesReturnsTrueWhenEnabled(): void
    {
        $config = new ProvidesConfig(routes: true);

        self::assertTrue($config->hasRoutes());
    }

    #[Test]
    public function hasRoutesReturnsFalseWhenDisabled(): void
    {
        $config = new ProvidesConfig(routes: false);

        self::assertFalse($config->hasRoutes());
    }

    #[Test]
    public function hasMiddlewareReturnsTrueWhenNonEmpty(): void
    {
        $config = new ProvidesConfig(middleware: ['SomeMiddleware']);

        self::assertTrue($config->hasMiddleware());
    }

    #[Test]
    public function providesAnythingReturnsTrueWithServices(): void
    {
        $config = new ProvidesConfig(services: ['SomeService']);

        self::assertTrue($config->providesAnything());
    }

    #[Test]
    public function providesAnythingReturnsTrueWithRoutes(): void
    {
        $config = new ProvidesConfig(routes: true);

        self::assertTrue($config->providesAnything());
    }

    #[Test]
    public function providesAnythingReturnsFalseWhenEmpty(): void
    {
        $config = new ProvidesConfig();

        self::assertFalse($config->providesAnything());
    }

    // =========================================================================
    // Migrations field tests
    // =========================================================================

    #[Test]
    public function fromArrayParsesMigrationsField(): void
    {
        $config = ProvidesConfig::fromArray([
            'migrations' => ['database/migrations'],
        ]);

        self::assertSame(['database/migrations'], $config->migrations);
    }

    #[Test]
    public function fromArrayDefaultsToEmptyMigrations(): void
    {
        $config = ProvidesConfig::fromArray([]);

        self::assertSame([], $config->migrations);
    }

    #[Test]
    public function hasMigrationsReturnsTrueWhenPresent(): void
    {
        $config = new ProvidesConfig(migrations: ['src/Migration']);

        self::assertTrue($config->hasMigrations());
    }

    #[Test]
    public function hasMigrationsReturnsFalseWhenEmpty(): void
    {
        $config = new ProvidesConfig();

        self::assertFalse($config->hasMigrations());
    }

    #[Test]
    public function providesAnythingReturnsTrueWithMigrationsOnly(): void
    {
        $config = new ProvidesConfig(migrations: ['database/migrations']);

        self::assertTrue($config->providesAnything());
    }

    #[Test]
    public function fromArrayThrowsOnNonArrayMigrations(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessage('provides.migrations');

        $_ = ProvidesConfig::fromArray([
            'migrations' => 'not-an-array',
        ]);
    }

    #[Test]
    public function fromArrayThrowsOnAssociativeMigrations(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessage('provides.migrations');

        $_ = ProvidesConfig::fromArray([
            'migrations' => ['key' => 'value'],
        ]);
    }
}
