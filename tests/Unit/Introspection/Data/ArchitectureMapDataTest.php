<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Introspection\Data;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Introspection\Data\ArchitectureMapData;
use Pulsar\Introspection\Data\ExtensionEntry;

#[CoversClass(ArchitectureMapData::class)]
final class ArchitectureMapDataTest extends TestCase
{
    #[Test]
    public function constructorDefaultsToEmpty(): void
    {
        $map = new ArchitectureMapData();

        self::assertSame([], $map->extensions);
        self::assertSame([], $map->bindings);
    }

    #[Test]
    public function constructorAcceptsExtensionsAndBindings(): void
    {
        $extension = new ExtensionEntry(
            name: 'cms',
            version: '1.0.0',
            state: 'booted',
        );

        $map = new ArchitectureMapData(
            extensions: [$extension],
            bindings: ['Pulsar\\Http\\Kernel'],
        );

        self::assertCount(1, $map->extensions);
        self::assertSame('cms', $map->extensions[0]->name);
        self::assertSame(['Pulsar\\Http\\Kernel'], $map->bindings);
    }

    #[Test]
    public function toArraySerializesExtensionsAndBindings(): void
    {
        $extension = new ExtensionEntry(
            name: 'auth',
            version: '2.0.0',
            state: 'registered',
            provides: ['AuthService'],
            dependencies: ['core'],
        );

        $map = new ArchitectureMapData(
            extensions: [$extension],
            bindings: ['App\\Service'],
        );

        $array = $map->toArray();

        self::assertArrayHasKey('extensions', $array);
        self::assertArrayHasKey('bindings', $array);
        self::assertCount(1, $array['extensions']);
        self::assertSame('auth', $array['extensions'][0]['name']);
        self::assertSame(['App\\Service'], $array['bindings']);
    }
}
