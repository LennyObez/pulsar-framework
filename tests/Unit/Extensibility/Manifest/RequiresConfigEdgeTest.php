<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extensibility\Manifest;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extensibility\Manifest\RequiresConfig;

#[CoversClass(RequiresConfig::class)]
final class RequiresConfigEdgeTest extends TestCase
{
    #[Test]
    public function constructionWithEmptyArray(): void
    {
        $config = new RequiresConfig();

        self::assertSame([], $config->extensions);
        self::assertFalse($config->hasDependencies());
        self::assertSame([], $config->getExtensionNames());
    }

    #[Test]
    public function fromArrayCreatesConfig(): void
    {
        $config = RequiresConfig::fromArray([
            'vendor/extension-a' => '^1.0',
            'vendor/extension-b' => '>=2.0.0',
        ]);

        self::assertTrue($config->hasDependencies());
        self::assertSame(['^1.0'], [$config->getVersionConstraint('vendor/extension-a')]);
    }

    #[Test]
    public function getExtensionNamesReturnsList(): void
    {
        $config = new RequiresConfig(extensions: [
            'ext-a' => '^1.0',
            'ext-b' => '^2.0',
            'ext-c' => '^3.0',
        ]);

        $names = $config->getExtensionNames();

        self::assertCount(3, $names);
        self::assertContains('ext-a', $names);
        self::assertContains('ext-b', $names);
        self::assertContains('ext-c', $names);
    }

    #[Test]
    public function getVersionConstraintReturnsNullForUnknown(): void
    {
        $config = new RequiresConfig(extensions: ['ext-a' => '^1.0']);

        self::assertNull($config->getVersionConstraint('nonexistent'));
    }

    #[Test]
    public function requiresReturnsTrueForKnownExtension(): void
    {
        $config = new RequiresConfig(extensions: ['ext-a' => '^1.0']);

        self::assertTrue($config->requires('ext-a'));
    }

    #[Test]
    public function requiresReturnsFalseForUnknownExtension(): void
    {
        $config = new RequiresConfig(extensions: ['ext-a' => '^1.0']);

        self::assertFalse($config->requires('ext-b'));
    }

    #[Test]
    public function fromArrayWithEmptyInput(): void
    {
        $config = RequiresConfig::fromArray([]);

        self::assertFalse($config->hasDependencies());
        self::assertSame([], $config->getExtensionNames());
    }

    #[Test]
    public function getVersionConstraintReturnsExactString(): void
    {
        $config = new RequiresConfig(extensions: ['my-ext' => '>=1.5.0, <2.0']);

        self::assertSame('>=1.5.0, <2.0', $config->getVersionConstraint('my-ext'));
    }
}
