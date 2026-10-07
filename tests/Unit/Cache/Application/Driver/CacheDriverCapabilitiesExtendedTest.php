<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache\Application\Driver;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\Driver\CacheDriverCapabilities;

#[CoversClass(CacheDriverCapabilities::class)]
final class CacheDriverCapabilitiesExtendedTest extends TestCase
{
    #[Test]
    public function allDefaultsAreFalse(): void
    {
        $capabilities = new CacheDriverCapabilities();

        self::assertFalse($capabilities->supportsTagsStrict);
        self::assertFalse($capabilities->supportsLocksFencing);
        self::assertFalse($capabilities->supportsBinary);
        self::assertFalse($capabilities->supportsAtomicIncrement);
    }

    #[Test]
    public function individualCapabilitiesCanBeEnabled(): void
    {
        $capabilities = new CacheDriverCapabilities(supportsTagsStrict: true);

        self::assertTrue($capabilities->supportsTagsStrict);
        self::assertFalse($capabilities->supportsLocksFencing);
        self::assertFalse($capabilities->supportsBinary);
        self::assertFalse($capabilities->supportsAtomicIncrement);
    }

    #[Test]
    public function allCapabilitiesCanBeEnabled(): void
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
    public function locksFencingCanBeEnabledAlone(): void
    {
        $capabilities = new CacheDriverCapabilities(supportsLocksFencing: true);

        self::assertFalse($capabilities->supportsTagsStrict);
        self::assertTrue($capabilities->supportsLocksFencing);
    }

    #[Test]
    public function binaryAndIncrementCanBeEnabledTogether(): void
    {
        $capabilities = new CacheDriverCapabilities(
            supportsBinary: true,
            supportsAtomicIncrement: true,
        );

        self::assertTrue($capabilities->supportsBinary);
        self::assertTrue($capabilities->supportsAtomicIncrement);
        self::assertFalse($capabilities->supportsTagsStrict);
        self::assertFalse($capabilities->supportsLocksFencing);
    }
}
