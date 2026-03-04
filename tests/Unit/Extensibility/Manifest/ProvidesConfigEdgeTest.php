<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extensibility\Manifest;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extensibility\Exception\ManifestException;
use Pulsar\Extensibility\Manifest\ProvidesConfig;

#[CoversClass(ProvidesConfig::class)]
final class ProvidesConfigEdgeTest extends TestCase
{
    #[Test]
    public function fromArrayThrowsWhenServicesIsNotArray(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessage('provides.services');

        $_ = ProvidesConfig::fromArray(['services' => 'not-array']);
    }

    #[Test]
    public function fromArrayThrowsWhenCommandsIsNotArray(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessage('provides.commands');

        $_ = ProvidesConfig::fromArray(['commands' => 42]);
    }

    #[Test]
    public function fromArrayThrowsWhenMiddlewareIsNotArray(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessage('provides.middleware');

        $_ = ProvidesConfig::fromArray(['middleware' => true]);
    }

    #[Test]
    public function fromArrayHandlesNonBoolRoutes(): void
    {
        $config = ProvidesConfig::fromArray(['routes' => 'yes']);

        // Non-bool routes defaults to false
        self::assertFalse($config->routes);
    }

    #[Test]
    public function providesAnythingReturnsTrueWithCommands(): void
    {
        $config = new ProvidesConfig(commands: ['App\\Command']);

        self::assertTrue($config->providesAnything());
    }

    #[Test]
    public function providesAnythingReturnsTrueWithMiddleware(): void
    {
        $config = new ProvidesConfig(middleware: ['App\\Middleware']);

        self::assertTrue($config->providesAnything());
    }

    #[Test]
    public function fromArrayWithEmptyListsProducesNoProvisions(): void
    {
        $config = ProvidesConfig::fromArray([
            'services' => [],
            'commands' => [],
            'routes' => false,
            'middleware' => [],
        ]);

        self::assertFalse($config->providesAnything());
    }

    #[Test]
    public function fromArrayWithMultipleServices(): void
    {
        $config = ProvidesConfig::fromArray([
            'services' => ['SvcA', 'SvcB', 'SvcC'],
        ]);

        self::assertCount(3, $config->services);
        self::assertSame('SvcA', $config->services[0]);
        self::assertSame('SvcC', $config->services[2]);
    }
}
