<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Analytics\Internal\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Internal\Security\AnalyticsKeyManager;
use Pulsar\Security\Crypto\MasterKey;

#[CoversClass(AnalyticsKeyManager::class)]
final class AnalyticsKeyManagerTest extends TestCase
{
    private AnalyticsKeyManager $manager;

    protected function setUp(): void
    {
        $this->manager = new AnalyticsKeyManager(
            MasterKey::fromHex(bin2hex(random_bytes(32))),
        );
    }

    #[Test]
    public function visitorKeyReturnsNonEmptyString(): void
    {
        $key = $this->manager->visitorKey();

        self::assertNotEmpty($key);
    }

    #[Test]
    public function visitorKeyIsDeterministic(): void
    {
        $first = $this->manager->visitorKey();
        $second = $this->manager->visitorKey();

        self::assertSame($first, $second, 'Visitor key should be deterministic (single subkey ID)');
    }

    #[Test]
    public function utcDayNumberReturnsDifferentValuesForDifferentOffsets(): void
    {
        $today = $this->manager->utcDayNumber(0);
        $yesterday = $this->manager->utcDayNumber(1);

        self::assertNotSame($today, $yesterday, 'Today and yesterday should have different day numbers');
        self::assertSame($today - 1, $yesterday, 'Yesterday day number should be today minus 1');
    }

    #[Test]
    public function utcDayNumberIsPositiveInteger(): void
    {
        $dayNumber = $this->manager->utcDayNumber(0);

        self::assertGreaterThan(0, $dayNumber, 'Day number should be positive');
        // Days since Unix epoch — in 2024+ this is at least ~19700
        self::assertGreaterThan(19000, $dayNumber, 'Day number should be days since Unix epoch');
    }

    #[Test]
    public function utcDayNumberTodayIsDeterministic(): void
    {
        $first = $this->manager->utcDayNumber(0);
        $second = $this->manager->utcDayNumber(0);

        self::assertSame($first, $second, 'Same offset should produce the same day number');
    }

    #[Test]
    public function utcDayNumberWithLargeOffsetGoesBack(): void
    {
        $today = $this->manager->utcDayNumber(0);
        $weekAgo = $this->manager->utcDayNumber(7);

        self::assertSame($today - 7, $weekAgo, '7-day offset should subtract 7 from day number');
    }
}
