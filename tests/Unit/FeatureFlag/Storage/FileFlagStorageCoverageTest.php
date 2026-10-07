<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\FeatureFlag\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\FeatureFlag\Exception\FeatureFlagException;
use Pulsar\FeatureFlag\FlagDefinition;
use Pulsar\FeatureFlag\FlagType;
use Pulsar\FeatureFlag\Storage\FileFlagStorage;

#[CoversClass(FileFlagStorage::class)]
final class FileFlagStorageCoverageTest extends TestCase
{
    private string $tempFile;

    protected function setUp(): void
    {
        $this->tempFile = tempnam(sys_get_temp_dir(), 'pulsar_flags_') ?: '';
        self::assertNotEmpty($this->tempFile);
        unlink($this->tempFile); // Start without the file
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    #[Test]
    public function getReturnsNullWhenFileDoesNotExist(): void
    {
        $storage = new FileFlagStorage($this->tempFile);

        self::assertNull($storage->get('nonexistent'));
    }

    #[Test]
    public function hasReturnsFalseWhenFileDoesNotExist(): void
    {
        $storage = new FileFlagStorage($this->tempFile);

        self::assertFalse($storage->has('anything'));
    }

    #[Test]
    public function allReturnsEmptyWhenFileDoesNotExist(): void
    {
        $storage = new FileFlagStorage($this->tempFile);

        self::assertSame([], $storage->all());
    }

    #[Test]
    public function setCreatesFileAndStoresFlag(): void
    {
        $storage = new FileFlagStorage($this->tempFile);

        $flag = new FlagDefinition(
            name: 'test-flag',
            enabled: true,
            type: FlagType::Boolean,
            description: 'A test flag',
        );

        $storage->set($flag);

        self::assertFileExists($this->tempFile);

        $retrieved = $storage->get('test-flag');
        self::assertNotNull($retrieved);
        self::assertSame('test-flag', $retrieved->name);
        self::assertTrue($retrieved->enabled);
        self::assertSame(FlagType::Boolean, $retrieved->type);
    }

    #[Test]
    public function setOverwritesExistingFlag(): void
    {
        $storage = new FileFlagStorage($this->tempFile);

        $storage->set(new FlagDefinition(name: 'flag', enabled: true));
        $storage->set(new FlagDefinition(name: 'flag', enabled: false, description: 'updated'));

        // Fresh storage instance to force re-read
        $storage2 = new FileFlagStorage($this->tempFile);
        $flag = $storage2->get('flag');

        self::assertNotNull($flag);
        self::assertFalse($flag->enabled);
        self::assertSame('updated', $flag->description);
    }

    #[Test]
    public function removeDeletesFlagFromFile(): void
    {
        $storage = new FileFlagStorage($this->tempFile);

        $storage->set(new FlagDefinition(name: 'flag-a', enabled: true));
        $storage->set(new FlagDefinition(name: 'flag-b', enabled: true));

        $storage->remove('flag-a');

        self::assertNull($storage->get('flag-a'));
        self::assertNotNull($storage->get('flag-b'));
    }

    #[Test]
    public function hasReturnsTrueForExistingFlag(): void
    {
        $storage = new FileFlagStorage($this->tempFile);

        $storage->set(new FlagDefinition(name: 'exists', enabled: true));

        self::assertTrue($storage->has('exists'));
        self::assertFalse($storage->has('not-exists'));
    }

    #[Test]
    public function allReturnsAllFlags(): void
    {
        $storage = new FileFlagStorage($this->tempFile);

        $storage->set(new FlagDefinition(name: 'flag-a', enabled: true));
        $storage->set(new FlagDefinition(name: 'flag-b', enabled: false));

        $all = $storage->all();

        self::assertCount(2, $all);
        self::assertArrayHasKey('flag-a', $all);
        self::assertArrayHasKey('flag-b', $all);
    }

    #[Test]
    public function loadUsesCache(): void
    {
        $storage = new FileFlagStorage($this->tempFile);

        $storage->set(new FlagDefinition(name: 'cached', enabled: true));

        // Second read should use cache
        $first = $storage->get('cached');
        $second = $storage->get('cached');

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame($first->name, $second->name);
    }

    #[Test]
    public function invalidJsonThrowsStorageError(): void
    {
        file_put_contents($this->tempFile, 'not valid json');

        $storage = new FileFlagStorage($this->tempFile);

        $this->expectException(FeatureFlagException::class);
        $this->expectExceptionMessageIsOrContains('Invalid JSON');

        $storage->all();
    }

    #[Test]
    public function nonArrayJsonThrowsStorageError(): void
    {
        file_put_contents($this->tempFile, '"just a string"');

        $storage = new FileFlagStorage($this->tempFile);

        $this->expectException(FeatureFlagException::class);
        $this->expectExceptionMessageIsOrContains('Expected JSON object');

        $storage->all();
    }

    #[Test]
    public function flagWithPercentageAndContextualType(): void
    {
        $storage = new FileFlagStorage($this->tempFile);

        $storage->set(new FlagDefinition(
            name: 'complex',
            enabled: true,
            type: FlagType::Contextual,
            percentage: 75,
            allowedTenants: ['acme'],
            allowedUsers: ['user-1'],
            allowedEnvironments: ['prod'],
        ));

        // Fresh instance to force reload from file
        $storage2 = new FileFlagStorage($this->tempFile);
        $flag = $storage2->get('complex');

        self::assertNotNull($flag);
        self::assertSame(FlagType::Contextual, $flag->type);
        self::assertSame(75, $flag->percentage);
        self::assertSame(['acme'], $flag->allowedTenants);
        self::assertSame(['user-1'], $flag->allowedUsers);
        self::assertSame(['prod'], $flag->allowedEnvironments);
    }

    #[Test]
    public function removeNonExistentFlagIsNoOp(): void
    {
        $storage = new FileFlagStorage($this->tempFile);

        $storage->set(new FlagDefinition(name: 'keep', enabled: true));
        $storage->remove('not-here');

        self::assertTrue($storage->has('keep'));
    }
}
