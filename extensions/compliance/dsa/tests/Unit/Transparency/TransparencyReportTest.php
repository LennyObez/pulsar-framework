<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dsa\Tests\Unit\Transparency;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Dsa\Transparency\TransparencyReport;

#[CoversClass(TransparencyReport::class)]
final class TransparencyReportTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $periodStart = new DateTimeImmutable('2025-01-01');
        $periodEnd = new DateTimeImmutable('2025-12-31');
        $generatedAt = new DateTimeImmutable('2026-02-15T10:00:00+00:00');

        $report = new TransparencyReport(
            reportId: 'rpt-2025',
            platformName: 'Example Platform',
            platformType: 'platform',
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            generatedAt: $generatedAt,
            totalModerationActions: 5000,
            actionsByType: ['remove' => 3000, 'restrict' => 1500, 'label' => 500],
            actionsByDetection: ['automated' => 3500, 'human' => 1000, 'trusted_flagger' => 500],
            appealsReceived: 200,
            appealsUpheld: 150,
            appealsOverturned: 50,
            ordersFromAuthorities: 10,
            trustedFlaggerNotices: 500,
            medianProcessingHours: 4.5,
            contactPoint: 'dsa@example.com',
        );

        self::assertSame('rpt-2025', $report->reportId);
        self::assertSame('Example Platform', $report->platformName);
        self::assertSame('platform', $report->platformType);
        self::assertSame($periodStart, $report->periodStart);
        self::assertSame($periodEnd, $report->periodEnd);
        self::assertSame($generatedAt, $report->generatedAt);
        self::assertSame(5000, $report->totalModerationActions);
        self::assertSame(3000, $report->actionsByType['remove']);
        self::assertSame(3500, $report->actionsByDetection['automated']);
        self::assertSame(200, $report->appealsReceived);
        self::assertSame(150, $report->appealsUpheld);
        self::assertSame(50, $report->appealsOverturned);
        self::assertSame(10, $report->ordersFromAuthorities);
        self::assertSame(500, $report->trustedFlaggerNotices);
        self::assertSame(4.5, $report->medianProcessingHours);
        self::assertSame('dsa@example.com', $report->contactPoint);
    }

    #[Test]
    public function constructorUsesDefaultsForOptionalProperties(): void
    {
        $report = new TransparencyReport(
            reportId: 'rpt-min',
            platformName: 'Test',
            platformType: 'hosting',
            periodStart: new DateTimeImmutable('2025-01-01'),
            periodEnd: new DateTimeImmutable('2025-12-31'),
            generatedAt: new DateTimeImmutable(),
            totalModerationActions: 0,
            actionsByType: [],
            actionsByDetection: [],
        );

        self::assertSame(0, $report->appealsReceived);
        self::assertSame(0, $report->appealsUpheld);
        self::assertSame(0, $report->appealsOverturned);
        self::assertSame(0, $report->ordersFromAuthorities);
        self::assertSame(0, $report->trustedFlaggerNotices);
        self::assertSame(0.0, $report->medianProcessingHours);
        self::assertSame('', $report->contactPoint);
    }

    #[Test]
    public function toArrayReturnsStructuredData(): void
    {
        $periodStart = new DateTimeImmutable('2025-01-01');
        $periodEnd = new DateTimeImmutable('2025-12-31');
        $generatedAt = new DateTimeImmutable('2026-02-15T10:00:00+00:00');

        $report = new TransparencyReport(
            reportId: 'rpt-arr',
            platformName: 'Test Platform',
            platformType: 'vlop',
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            generatedAt: $generatedAt,
            totalModerationActions: 100,
            actionsByType: ['remove' => 60, 'restrict' => 40],
            actionsByDetection: ['human' => 100],
            appealsReceived: 5,
            appealsUpheld: 3,
            appealsOverturned: 2,
        );

        $array = $report->toArray();

        self::assertSame('rpt-arr', $array['report_id']);
        self::assertSame('Test Platform', $array['platform_name']);
        self::assertSame('vlop', $array['platform_type']);
        self::assertSame('2025-01-01', $array['period_start']);
        self::assertSame('2025-12-31', $array['period_end']);
        self::assertSame('2026-02-15T10:00:00+00:00', $array['generated_at']);
        self::assertSame(100, $array['total_moderation_actions']);
        self::assertSame(['remove' => 60, 'restrict' => 40], $array['actions_by_type']);
        self::assertSame(['human' => 100], $array['actions_by_detection']);
        self::assertSame(5, $array['appeals_received']);
        self::assertSame(3, $array['appeals_upheld']);
        self::assertSame(2, $array['appeals_overturned']);
    }
}
