<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\DataProtection;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\DataProtection\ConsentRecordInterface;

final class ConsentRecordInterfaceTest extends TestCase
{
    #[Test]
    public function subjectIdReturnsString(): void
    {
        $record = $this->createStub(ConsentRecordInterface::class);
        $record->method('subjectId')->willReturn('subject-42');

        self::assertSame('subject-42', $record->subjectId());
    }

    #[Test]
    public function purposeReturnsString(): void
    {
        $record = $this->createStub(ConsentRecordInterface::class);
        $record->method('purpose')->willReturn('data_sharing_third_party');

        self::assertSame('data_sharing_third_party', $record->purpose());
    }

    #[Test]
    public function isGrantedReturnsTrueForActiveConsent(): void
    {
        $record = $this->createStub(ConsentRecordInterface::class);
        $record->method('isGranted')->willReturn(true);

        self::assertTrue($record->isGranted());
    }

    #[Test]
    public function isGrantedReturnsFalseForRevokedConsent(): void
    {
        $record = $this->createStub(ConsentRecordInterface::class);
        $record->method('isGranted')->willReturn(false);

        self::assertFalse($record->isGranted());
    }

    #[Test]
    public function recordedAtReturnsDateTimeImmutable(): void
    {
        $ts = new DateTimeImmutable('2025-06-15T10:30:00+00:00');
        $record = $this->createStub(ConsentRecordInterface::class);
        $record->method('recordedAt')->willReturn($ts);

        self::assertSame($ts, $record->recordedAt());
    }

    #[Test]
    public function policyVersionReturnsVersionString(): void
    {
        $record = $this->createStub(ConsentRecordInterface::class);
        $record->method('policyVersion')->willReturn('v3.1');

        self::assertSame('v3.1', $record->policyVersion());
    }

    #[Test]
    public function policyVersionReturnsEmptyStringWhenNotApplicable(): void
    {
        $record = $this->createStub(ConsentRecordInterface::class);
        $record->method('policyVersion')->willReturn('');

        self::assertSame('', $record->policyVersion());
    }
}
