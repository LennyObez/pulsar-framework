<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache\Application\Driver;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\Driver\CacheDriverCapabilities;

#[CoversClass(CacheDriverCapabilities::class)]
final class CacheDriverCapabilitiesTest extends TestCase
{
    #[Test]
    public function constructorDefaultsAllFalse(): void
    {
        $capabilities = new CacheDriverCapabilities();

        self::assertFalse($capabilities->supportsTagsStrict);
        self::assertFalse($capabilities->supportsLocksFencing);
        self::assertFalse($capabilities->supportsBinary);
        self::assertFalse($capabilities->supportsAtomicIncrement);
    }

    #[Test]
    public function constructorWithAllTrue(): void
    {
        $capabilities = new CacheDriverCapabilities(
            supportsTagsStrict: true,
            supportsLocksFencing: true,
            supportsBinary: true,
            supportsAtomicIncrement: true,
        );

        self::assertTrue($capabilities->supportsTagsStrict);
        self::assertTrue($capabilities->supportsLocksFencing);
        self::assertTrue($capabilities->supportsBinary);
        self::assertTrue($capabilities->supportsAtomicIncrement);
    }

    #[Test]
    public function individualFlagsCanBeSetIndependently(): void
    {
        $tagsOnly = new CacheDriverCapabilities(supportsTagsStrict: true);
        self::assertTrue($tagsOnly->supportsTagsStrict);
        self::assertFalse($tagsOnly->supportsLocksFencing);
        self::assertFalse($tagsOnly->supportsBinary);
        self::assertFalse($tagsOnly->supportsAtomicIncrement);

        $locksOnly = new CacheDriverCapabilities(supportsLocksFencing: true);
        self::assertFalse($locksOnly->supportsTagsStrict);
        self::assertTrue($locksOnly->supportsLocksFencing);
        self::assertFalse($locksOnly->supportsBinary);
        self::assertFalse($locksOnly->supportsAtomicIncrement);

        $binaryOnly = new CacheDriverCapabilities(supportsBinary: true);
        self::assertFalse($binaryOnly->supportsTagsStrict);
        self::assertFalse($binaryOnly->supportsLocksFencing);
        self::assertTrue($binaryOnly->supportsBinary);
        self::assertFalse($binaryOnly->supportsAtomicIncrement);

        $incrementOnly = new CacheDriverCapabilities(supportsAtomicIncrement: true);
        self::assertFalse($incrementOnly->supportsTagsStrict);
        self::assertFalse($incrementOnly->supportsLocksFencing);
        self::assertFalse($incrementOnly->supportsBinary);
        self::assertTrue($incrementOnly->supportsAtomicIncrement);
    }
}
