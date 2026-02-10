<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Analyzer\Policy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Analyzer\FindingSeverity;
use Pulsar\Http\Validation\Analyzer\Policy\RetentionPeriodAnalyzer;

use function date;
use function strtotime;

#[CoversClass(RetentionPeriodAnalyzer::class)]
final class RetentionPeriodAnalyzerTest extends TestCase
{
    #[Test]
    public function expiredDataProducesWarningFinding(): void
    {
        $analyzer = new RetentionPeriodAnalyzer(retentionDays: 365);
        $oldDate = date('Y-m-d', strtotime('-400 days'));

        $findings = $analyzer->analyze('created_at', $oldDate, []);

        self::assertCount(1, $findings);
        self::assertSame(FindingSeverity::Warning, $findings[0]->severity);
        self::assertSame(0.8, $findings[0]->confidence);
        self::assertStringContainsString('exceeding retention period', $findings[0]->pattern);
    }

    #[Test]
    public function recentDataReturnsEmpty(): void
    {
        $analyzer = new RetentionPeriodAnalyzer(retentionDays: 365);
        $recentDate = date('Y-m-d', strtotime('-30 days'));

        self::assertSame([], $analyzer->analyze('created_at', $recentDate, []));
    }

    #[Test]
    public function exactRetentionBoundaryReturnsEmpty(): void
    {
        $analyzer = new RetentionPeriodAnalyzer(retentionDays: 365);
        $borderDate = date('Y-m-d', strtotime('-365 days'));

        self::assertSame([], $analyzer->analyze('created_at', $borderDate, []));
    }

    #[Test]
    public function oneDayPastRetentionProducesFinding(): void
    {
        $analyzer = new RetentionPeriodAnalyzer(retentionDays: 365);
        $oldDate = date('Y-m-d', strtotime('-366 days'));

        $findings = $analyzer->analyze('created_at', $oldDate, []);

        self::assertCount(1, $findings);
    }

    #[Test]
    public function customRetentionPeriod(): void
    {
        $analyzer = new RetentionPeriodAnalyzer(retentionDays: 30);
        $oldDate = date('Y-m-d', strtotime('-45 days'));

        $findings = $analyzer->analyze('created_at', $oldDate, []);

        self::assertCount(1, $findings);
        self::assertStringContainsString('30 days', $findings[0]->pattern);
    }

    #[Test]
    public function isoDateTimeFormat(): void
    {
        $analyzer = new RetentionPeriodAnalyzer(retentionDays: 365);
        $oldDate = date('Y-m-d\TH:i:s', strtotime('-400 days'));

        $findings = $analyzer->analyze('created_at', $oldDate, []);

        self::assertCount(1, $findings);
    }

    #[Test]
    public function sqlDateTimeFormat(): void
    {
        $analyzer = new RetentionPeriodAnalyzer(retentionDays: 365);
        $oldDate = date('Y-m-d H:i:s', strtotime('-400 days'));

        $findings = $analyzer->analyze('created_at', $oldDate, []);

        self::assertCount(1, $findings);
    }

    #[Test]
    public function todayDateReturnsEmpty(): void
    {
        $analyzer = new RetentionPeriodAnalyzer(retentionDays: 365);
        $today = date('Y-m-d');

        self::assertSame([], $analyzer->analyze('created_at', $today, []));
    }

    #[Test]
    public function invalidDateReturnsEmpty(): void
    {
        $analyzer = new RetentionPeriodAnalyzer();

        self::assertSame([], $analyzer->analyze('created_at', 'not-a-date', []));
    }

    #[Test]
    public function emptyStringReturnsEmpty(): void
    {
        $analyzer = new RetentionPeriodAnalyzer();

        self::assertSame([], $analyzer->analyze('created_at', '', []));
    }

    #[Test]
    public function nullReturnsEmpty(): void
    {
        $analyzer = new RetentionPeriodAnalyzer();

        self::assertSame([], $analyzer->analyze('created_at', null, []));
    }

    #[Test]
    public function nonStringReturnsEmpty(): void
    {
        $analyzer = new RetentionPeriodAnalyzer();

        self::assertSame([], $analyzer->analyze('created_at', 12345, []));
    }

    #[Test]
    public function findingRecommendationMentionsRetention(): void
    {
        $analyzer = new RetentionPeriodAnalyzer(retentionDays: 365);
        $oldDate = date('Y-m-d', strtotime('-400 days'));

        $findings = $analyzer->analyze('created_at', $oldDate, []);

        self::assertCount(1, $findings);
        self::assertStringContainsString('retention', $findings[0]->recommendation);
    }

    #[Test]
    public function findingPreservesFieldName(): void
    {
        $analyzer = new RetentionPeriodAnalyzer(retentionDays: 365);
        $oldDate = date('Y-m-d', strtotime('-400 days'));

        $findings = $analyzer->analyze('audit_timestamp', $oldDate, []);

        self::assertCount(1, $findings);
        self::assertSame('audit_timestamp', $findings[0]->field);
        self::assertStringContainsString('audit_timestamp', $findings[0]->recommendation);
    }

    #[Test]
    public function defaultRetentionIsOneYear(): void
    {
        $analyzer = new RetentionPeriodAnalyzer();
        $oldDate = date('Y-m-d', strtotime('-400 days'));

        $findings = $analyzer->analyze('created_at', $oldDate, []);

        self::assertCount(1, $findings);
        self::assertStringContainsString('365 days', $findings[0]->pattern);
    }

    #[Test]
    public function futureDateReturnsEmpty(): void
    {
        $analyzer = new RetentionPeriodAnalyzer(retentionDays: 365);
        $futureDate = date('Y-m-d', strtotime('+500 days'));

        self::assertSame([], $analyzer->analyze('expires_at', $futureDate, []));
    }
}
