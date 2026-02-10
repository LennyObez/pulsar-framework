<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Introspection\Data;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Introspection\Data\ExtensionEntry;

#[CoversClass(ExtensionEntry::class)]
final class ExtensionEntryTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $entry = new ExtensionEntry(
            name: 'cms',
            version: '2.1.0',
            state: 'booted',
            provides: ['ContentService', 'MediaService'],
            dependencies: ['core', 'auth'],
        );

        self::assertSame('cms', $entry->name);
        self::assertSame('2.1.0', $entry->version);
        self::assertSame('booted', $entry->state);
        self::assertSame(['ContentService', 'MediaService'], $entry->provides);
        self::assertSame(['core', 'auth'], $entry->dependencies);
    }

    #[Test]
    public function constructorDefaultsToEmptyProvidesAndDependencies(): void
    {
        $entry = new ExtensionEntry(
            name: 'minimal',
            version: '1.0.0',
            state: 'registered',
        );

        self::assertSame([], $entry->provides);
        self::assertSame([], $entry->dependencies);
    }

    #[Test]
    public function toArrayReturnsExpectedStructure(): void
    {
        $entry = new ExtensionEntry(
            name: 'forum',
            version: '1.0.0',
            state: 'booted',
            provides: ['ForumService'],
            dependencies: ['core'],
        );

        $array = $entry->toArray();

        self::assertSame('forum', $array['name']);
        self::assertSame('1.0.0', $array['version']);
        self::assertSame('booted', $array['state']);
        self::assertSame(['ForumService'], $array['provides']);
        self::assertSame(['core'], $array['dependencies']);
    }
}
