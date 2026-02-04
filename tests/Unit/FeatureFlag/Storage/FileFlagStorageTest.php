<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\FeatureFlag\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\FeatureFlag\FlagDefinition;
use Pulsar\FeatureFlag\FlagType;
use Pulsar\FeatureFlag\Storage\FileFlagStorage;

#[CoversClass(FileFlagStorage::class)]
final class FileFlagStorageTest extends TestCase
{
    private string $tempFile;

    protected function setUp(): void
    {
        $this->tempFile = sys_get_temp_dir() . '/pulsar_test_flags_' . uniqid() . '.json';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    #[Test]
    public function storesAndRetrievesFlagFromFile(): void
    {
        $storage = new FileFlagStorage($this->tempFile);
        $flag = new FlagDefinition(name: 'file-flag', enabled: true, type: FlagType::Boolean);

        $storage->set($flag);

        // Create a new instance to force re-reading from file
        $freshStorage = new FileFlagStorage($this->tempFile);
        $retrieved = $freshStorage->get('file-flag');

        self::assertNotNull($retrieved);
        self::assertSame('file-flag', $retrieved->name);
        self::assertTrue($retrieved->enabled);
        self::assertSame(FlagType::Boolean, $retrieved->type);
    }

    #[Test]
    public function hasWorksForFileStorage(): void
    {
        $storage = new FileFlagStorage($this->tempFile);
        $flag = new FlagDefinition(name: 'exists-flag', enabled: true, type: FlagType::Boolean);

        $storage->set($flag);

        $freshStorage = new FileFlagStorage($this->tempFile);

        self::assertTrue($freshStorage->has('exists-flag'));
        self::assertFalse($freshStorage->has('nonexistent'));
    }

    #[Test]
    public function allReturnsAllStoredFlags(): void
    {
        $storage = new FileFlagStorage($this->tempFile);
        $flagA = new FlagDefinition(name: 'alpha', enabled: true, type: FlagType::Boolean);
        $flagB = new FlagDefinition(name: 'beta', enabled: false, type: FlagType::Percentage, percentage: 30);

        $storage->set($flagA);
        $storage->set($flagB);

        $freshStorage = new FileFlagStorage($this->tempFile);
        $all = $freshStorage->all();

        self::assertCount(2, $all);
        self::assertArrayHasKey('alpha', $all);
        self::assertArrayHasKey('beta', $all);
    }

    #[Test]
    public function removePersistsRemoval(): void
    {
        $storage = new FileFlagStorage($this->tempFile);
        $flag = new FlagDefinition(name: 'to-remove', enabled: true, type: FlagType::Boolean);

        $storage->set($flag);
        $storage->remove('to-remove');

        $freshStorage = new FileFlagStorage($this->tempFile);

        self::assertFalse($freshStorage->has('to-remove'));
        self::assertNull($freshStorage->get('to-remove'));
    }

    #[Test]
    public function returnsEmptyWhenFileDoesNotExist(): void
    {
        $storage = new FileFlagStorage($this->tempFile);

        self::assertSame([], $storage->all());
        self::assertFalse($storage->has('any'));
        self::assertNull($storage->get('any'));
    }
}
