<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Compliance\Retention;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Compliance\Retention\RetentionPurgeResult;

#[CoversClass(RetentionPurgeResult::class)]
final class RetentionPurgeResultTest extends TestCase
{
    public function testConstructorAssignsProperties(): void
    {
        $start = new DateTimeImmutable('2025-01-01');
        $end = new DateTimeImmutable('2025-06-30');
        $executed = new DateTimeImmutable('2026-03-15T12:00:00+00:00');

        $result = new RetentionPurgeResult(
            policyId: 'pol-1',
            policyVersion: 3,
            affectedStartDate: $start,
            affectedEndDate: $end,
            recordCount: 1500,
            operatorIdentity: 'cron-job',
            executedAt: $executed,
            dryRun: false,
        );

        self::assertSame('pol-1', $result->policyId);
        self::assertSame(3, $result->policyVersion);
        self::assertSame(1500, $result->recordCount);
        self::assertSame('cron-job', $result->operatorIdentity);
        self::assertFalse($result->dryRun);
    }

    public function testToArrayContainsAllFields(): void
    {
        $result = new RetentionPurgeResult(
            policyId: 'pol-audit',
            policyVersion: 1,
            affectedStartDate: new DateTimeImmutable('2024-01-01T00:00:00.000000+00:00'),
            affectedEndDate: new DateTimeImmutable('2024-12-31T23:59:59.000000+00:00'),
            recordCount: 0,
            operatorIdentity: 'dpo',
            executedAt: new DateTimeImmutable('2026-03-15T10:00:00.000000+00:00'),
            dryRun: true,
        );

        $array = $result->toArray();

        self::assertSame('pol-audit', $array['policy_id']);
        self::assertSame(1, $array['policy_version']);
        self::assertSame(0, $array['record_count']);
        self::assertSame('dpo', $array['operator_identity']);
        self::assertTrue($array['dry_run']);
        self::assertArrayHasKey('affected_start_date', $array);
        self::assertArrayHasKey('affected_end_date', $array);
        self::assertArrayHasKey('executed_at', $array);
    }
}
