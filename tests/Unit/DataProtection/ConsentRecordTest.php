<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\DataProtection;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\DataProtection\ConsentRecord;

#[CoversClass(ConsentRecord::class)]
final class ConsentRecordTest extends TestCase
{
    #[Test]
    public function grantedRecordReturnsCorrectValues(): void
    {
        $recordedAt = new DateTimeImmutable('2026-03-14T10:00:00+00:00');

        $record = new ConsentRecord(
            subjectId: 'user-42',
            purpose: 'marketing_email',
            granted: true,
            recordedAt: $recordedAt,
            policyVersion: 'v2.1',
        );

        self::assertSame('user-42', $record->subjectId());
        self::assertSame('marketing_email', $record->purpose());
        self::assertTrue($record->isGranted());
        self::assertSame($recordedAt, $record->recordedAt());
        self::assertSame('v2.1', $record->policyVersion());
    }

    #[Test]
    public function revokedRecordReturnsFalseForIsGranted(): void
    {
        $record = new ConsentRecord(
            subjectId: 'user-99',
            purpose: 'analytics',
            granted: false,
            recordedAt: new DateTimeImmutable(),
        );

        self::assertFalse($record->isGranted());
    }

    #[Test]
    public function policyVersionDefaultsToEmptyString(): void
    {
        $record = new ConsentRecord(
            subjectId: 'user-1',
            purpose: 'data_sharing',
            granted: true,
            recordedAt: new DateTimeImmutable(),
        );

        self::assertSame('', $record->policyVersion());
    }
}
