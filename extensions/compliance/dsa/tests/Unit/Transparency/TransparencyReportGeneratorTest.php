<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dsa\Tests\Unit\Transparency;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Dsa\Config\DsaConfig;
use Pulsar\Extension\Dsa\ContentModeration\ModerationDecision;
use Pulsar\Extension\Dsa\Internal\InMemoryModerationLog;
use Pulsar\Extension\Dsa\Transparency\TransparencyReportGenerator;

#[CoversClass(TransparencyReportGenerator::class)]
final class TransparencyReportGeneratorTest extends TestCase
{
    #[Test]
    public function generateProducesReportFromLogData(): void
    {
        $log = new InMemoryModerationLog();
        $config = new DsaConfig(
            enabled: true,
            platformType: 'platform',
            contactPoint: 'dsa@example.com',
        );
        $generator = new TransparencyReportGenerator($log, $config);

        $log->record(new ModerationDecision(
            id: 'dec-1',
            contentId: 'post-1',
            contentType: 'post',
            decision: 'remove',
            reason: 'Illegal content',
            policyId: 'policy-1',
            detectionMethod: 'human',
            decidedAt: new DateTimeImmutable('2025-03-15T10:00:00+00:00'),
            appealUrl: 'https://example.com/appeal/dec-1',
        ));
        $log->record(new ModerationDecision(
            id: 'dec-2',
            contentId: 'post-2',
            contentType: 'comment',
            decision: 'remove',
            reason: 'Spam',
            policyId: 'policy-2',
            detectionMethod: 'automated',
            decidedAt: new DateTimeImmutable('2025-06-20T14:00:00+00:00'),
            appealUrl: 'https://example.com/appeal/dec-2',
        ));
        $log->record(new ModerationDecision(
            id: 'dec-3',
            contentId: 'post-3',
            contentType: 'post',
            decision: 'restrict',
            reason: 'Trusted flagger report',
            policyId: 'policy-1',
            detectionMethod: 'trusted_flagger',
            decidedAt: new DateTimeImmutable('2025-09-01T08:00:00+00:00'),
            appealUrl: 'https://example.com/appeal/dec-3',
        ));

        $report = $generator->generate(
            reportId: 'rpt-2025',
            platformName: 'Example Platform',
            periodStart: new DateTimeImmutable('2025-01-01T00:00:00+00:00'),
            periodEnd: new DateTimeImmutable('2025-12-31T23:59:59+00:00'),
            generatedAt: new DateTimeImmutable('2026-01-15T10:00:00+00:00'),
            appealsReceived: 10,
            appealsUpheld: 7,
            appealsOverturned: 3,
            ordersFromAuthorities: 2,
        );

        self::assertSame('rpt-2025', $report->reportId);
        self::assertSame('Example Platform', $report->platformName);
        self::assertSame('platform', $report->platformType);
        self::assertSame(3, $report->totalModerationActions);
        self::assertSame(2, $report->actionsByType['remove']);
        self::assertSame(1, $report->actionsByType['restrict']);
        self::assertSame(1, $report->actionsByDetection['human']);
        self::assertSame(1, $report->actionsByDetection['automated']);
        self::assertSame(1, $report->actionsByDetection['trusted_flagger']);
        self::assertSame(1, $report->trustedFlaggerNotices);
        self::assertSame(10, $report->appealsReceived);
        self::assertSame(7, $report->appealsUpheld);
        self::assertSame(3, $report->appealsOverturned);
        self::assertSame(2, $report->ordersFromAuthorities);
        self::assertSame('dsa@example.com', $report->contactPoint);
    }

    #[Test]
    public function generateWithEmptyLogProducesZeroReport(): void
    {
        $log = new InMemoryModerationLog();
        $config = new DsaConfig(platformType: 'hosting');
        $generator = new TransparencyReportGenerator($log, $config);

        $report = $generator->generate(
            reportId: 'rpt-empty',
            platformName: 'Empty Platform',
            periodStart: new DateTimeImmutable('2025-01-01T00:00:00+00:00'),
            periodEnd: new DateTimeImmutable('2025-12-31T23:59:59+00:00'),
            generatedAt: new DateTimeImmutable('2026-01-15T10:00:00+00:00'),
        );

        self::assertSame(0, $report->totalModerationActions);
        self::assertSame([], $report->actionsByType);
        self::assertSame([], $report->actionsByDetection);
        self::assertSame(0, $report->trustedFlaggerNotices);
        self::assertSame(0.0, $report->medianProcessingHours);
    }

    #[Test]
    public function generateUsesConfigContactPoint(): void
    {
        $log = new InMemoryModerationLog();
        $config = new DsaConfig(contactPoint: 'contact@platform.eu');
        $generator = new TransparencyReportGenerator($log, $config);

        $report = $generator->generate(
            reportId: 'rpt-cp',
            platformName: 'Test',
            periodStart: new DateTimeImmutable('2025-01-01'),
            periodEnd: new DateTimeImmutable('2025-12-31'),
            generatedAt: new DateTimeImmutable(),
        );

        self::assertSame('contact@platform.eu', $report->contactPoint);
    }
}
