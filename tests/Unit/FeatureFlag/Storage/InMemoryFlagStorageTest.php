<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\FeatureFlag\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\FeatureFlag\FlagDefinition;
use Pulsar\FeatureFlag\FlagType;
use Pulsar\FeatureFlag\Storage\InMemoryFlagStorage;

#[CoversClass(InMemoryFlagStorage::class)]
final class InMemoryFlagStorageTest extends TestCase
{
    #[Test]
    public function setAndGetRetrievesStoredFlag(): void
    {
        $storage = new InMemoryFlagStorage();
        $flag = new FlagDefinition(name: 'dark-mode', enabled: true, type: FlagType::Boolean);

        $storage->set($flag);
        $retrieved = $storage->get('dark-mode');

        self::assertNotNull($retrieved);
        self::assertSame('dark-mode', $retrieved->name);
        self::assertTrue($retrieved->enabled);
        self::assertSame(FlagType::Boolean, $retrieved->type);
    }

    #[Test]
    public function getReturnsNullForMissingFlag(): void
    {
        $storage = new InMemoryFlagStorage();

        self::assertNull($storage->get('nonexistent'));
    }

    #[Test]
    public function hasReturnsTrueForExistingFlag(): void
    {
        $storage = new InMemoryFlagStorage();
        $flag = new FlagDefinition(name: 'beta', enabled: false, type: FlagType::Boolean);

        $storage->set($flag);

        self::assertTrue($storage->has('beta'));
    }

    #[Test]
    public function hasReturnsFalseForMissingFlag(): void
    {
        $storage = new InMemoryFlagStorage();

        self::assertFalse($storage->has('missing'));
    }

    #[Test]
    public function allReturnsAllStoredFlags(): void
    {
        $storage = new InMemoryFlagStorage();
        $flagA = new FlagDefinition(name: 'flag-a', enabled: true, type: FlagType::Boolean);
        $flagB = new FlagDefinition(name: 'flag-b', enabled: false, type: FlagType::Percentage, percentage: 50);

        $storage->set($flagA);
        $storage->set($flagB);

        $all = $storage->all();

        self::assertCount(2, $all);
        self::assertArrayHasKey('flag-a', $all);
        self::assertArrayHasKey('flag-b', $all);
        self::assertSame('flag-a', $all['flag-a']->name);
        self::assertSame('flag-b', $all['flag-b']->name);
    }

    #[Test]
    public function removeDeletesFlag(): void
    {
        $storage = new InMemoryFlagStorage();
        $flag = new FlagDefinition(name: 'removable', enabled: true, type: FlagType::Boolean);

        $storage->set($flag);
        self::assertTrue($storage->has('removable'));

        $storage->remove('removable');

        self::assertFalse($storage->has('removable'));
        self::assertNull($storage->get('removable'));
    }
}
