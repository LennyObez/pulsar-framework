<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Internal\Security;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Internal\Security\AnalyticsKeyManager;
use Pulsar\Security\Crypto\KeyProviderInterface;

final class AnalyticsKeyManagerTest extends TestCase
{
    #[Test]
    public function visitor_key_returns_deterministic_value(): void
    {
        $masterKey = $this->createStub(KeyProviderInterface::class);
        $masterKey->method('deriveSubKey')->willReturn('derived_key_abc');

        $manager = new AnalyticsKeyManager($masterKey);
        $key = $manager->visitorKey();

        self::assertSame('derived_key_abc', $key);
    }

    #[Test]
    public function visitor_key_uses_correct_subkey_id_and_context(): void
    {
        $masterKey = $this->createStub(KeyProviderInterface::class);
        $masterKey->method('deriveSubKey')->willReturnCallback(
            static function (int $subkeyId, string $context): string {
                return "key_{$subkeyId}_{$context}";
            },
        );

        $manager = new AnalyticsKeyManager($masterKey);
        $key = $manager->visitorKey();

        self::assertSame('key_20_anal_vis', $key);
    }

    #[Test]
    public function utc_day_number_today(): void
    {
        $masterKey = $this->createStub(KeyProviderInterface::class);
        $manager = new AnalyticsKeyManager($masterKey);

        $dayNumber = $manager->utcDayNumber(0);

        // Should be roughly current timestamp / 86400
        $expected = (int) (time() / 86400);
        self::assertEqualsWithDelta($expected, $dayNumber, 1);
    }

    #[Test]
    public function utc_day_number_yesterday(): void
    {
        $masterKey = $this->createStub(KeyProviderInterface::class);
        $manager = new AnalyticsKeyManager($masterKey);

        $today = $manager->utcDayNumber(0);
        $yesterday = $manager->utcDayNumber(1);

        self::assertSame($today - 1, $yesterday);
    }

    #[Test]
    public function utc_day_number_monotonically_increasing(): void
    {
        $masterKey = $this->createStub(KeyProviderInterface::class);
        $manager = new AnalyticsKeyManager($masterKey);

        $day0 = $manager->utcDayNumber(0);
        $day1 = $manager->utcDayNumber(1);
        $day7 = $manager->utcDayNumber(7);

        self::assertGreaterThan($day1, $day0);
        self::assertGreaterThan($day7, $day1);
    }
}
