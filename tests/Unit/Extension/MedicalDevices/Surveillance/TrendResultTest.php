<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\MedicalDevices\Surveillance;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\MedicalDevices\Surveillance\TrendResult;

#[CoversClass(TrendResult::class)]
final class TrendResultTest extends TestCase
{
    #[Test]
    public function constructsWithRequiredFields(): void
    {
        $result = new TrendResult(
            deviceIdentifier: 'DI-001',
            periodStart: '2025-01-01',
            periodEnd: '2025-06-30',
            totalEvents: 15,
            significantIncrease: false,
        );

        self::assertSame('DI-001', $result->deviceIdentifier);
        self::assertSame(15, $result->totalEvents);
        self::assertFalse($result->significantIncrease);
        self::assertNull($result->changePercentage);
    }

    #[Test]
    public function toArrayOmitsNullOptionalFields(): void
    {
        $result = new TrendResult(
            deviceIdentifier: 'DI-001',
            periodStart: '2025-01-01',
            periodEnd: '2025-06-30',
            totalEvents: 5,
            significantIncrease: false,
        );

        $data = $result->toArray();

        self::assertArrayNotHasKey('change_percentage', $data);
        self::assertArrayNotHasKey('summary', $data);
        self::assertFalse($data['significant_increase']);
    }

    #[Test]
    public function toArrayIncludesSignificantIncrease(): void
    {
        $result = new TrendResult(
            deviceIdentifier: 'DI-001',
            periodStart: '2025-01-01',
            periodEnd: '2025-06-30',
            totalEvents: 50,
            significantIncrease: true,
            changePercentage: 45.5,
            summary: 'Significant increase in malfunction reports',
        );

        $data = $result->toArray();

        self::assertTrue($data['significant_increase']);
        self::assertSame(45.5, $data['change_percentage']);
        self::assertSame('Significant increase in malfunction reports', $data['summary']);
    }
}
