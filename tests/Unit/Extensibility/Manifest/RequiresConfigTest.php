<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extensibility\Manifest;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extensibility\Manifest\RequiresConfig;

#[CoversClass(RequiresConfig::class)]
final class RequiresConfigTest extends TestCase
{
    #[Test]
    public function defaultHasNoDependencies(): void
    {
        $config = new RequiresConfig();

        self::assertFalse($config->hasDependencies());
        self::assertSame([], $config->extensions);
        self::assertSame([], $config->getExtensionNames());
    }

    #[Test]
    public function fromArrayCreatesFromMap(): void
    {
        $config = RequiresConfig::fromArray([
            'pulsar/auth' => '^1.0',
            'pulsar/cache' => '^2.0',
        ]);

        self::assertTrue($config->hasDependencies());
        self::assertSame('^1.0', $config->getVersionConstraint('pulsar/auth'));
        self::assertSame('^2.0', $config->getVersionConstraint('pulsar/cache'));
    }

    #[Test]
    public function getExtensionNamesReturnsSortedKeys(): void
    {
        $config = new RequiresConfig([
            'pulsar/mail' => '^1.0',
            'pulsar/queue' => '^1.2',
        ]);

        $names = $config->getExtensionNames();

        self::assertContains('pulsar/mail', $names);
        self::assertContains('pulsar/queue', $names);
    }

    #[Test]
    public function getVersionConstraintReturnsNullForMissing(): void
    {
        $config = new RequiresConfig(['pulsar/auth' => '^1.0']);

        self::assertNull($config->getVersionConstraint('pulsar/nonexistent'));
    }

    #[Test]
    public function requiresReturnsTrueForExisting(): void
    {
        $config = new RequiresConfig(['pulsar/database' => '^1.0']);

        self::assertTrue($config->requires('pulsar/database'));
        self::assertFalse($config->requires('pulsar/cache'));
    }
}
