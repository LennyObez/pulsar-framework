<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\DataProtection\Dsar;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\DataProtection\Dsar\DsarRequest;
use Pulsar\DataProtection\Dsar\DsarStatus;

#[CoversClass(DsarRequest::class)]
final class DsarRequestTest extends TestCase
{
    #[Test]
    public function constructorSetsAllRequiredProperties(): void
    {
        $createdAt = new DateTimeImmutable('2026-01-01');
        $deadline = new DateTimeImmutable('2026-01-31');

        $request = new DsarRequest(
            id: 'req-abc',
            subjectId: 'user-123',
            email: 'user@example.com',
            status: DsarStatus::Pending,
            createdAt: $createdAt,
            deadline: $deadline,
        );

        self::assertSame('req-abc', $request->id);
        self::assertSame('user-123', $request->subjectId);
        self::assertSame('user@example.com', $request->email);
        self::assertSame(DsarStatus::Pending, $request->status);
        self::assertSame($createdAt, $request->createdAt);
        self::assertSame($deadline, $request->deadline);
        self::assertNull($request->completedAt);
        self::assertNull($request->packagePath);
        self::assertNull($request->verificationToken);
    }

    #[Test]
    public function constructorSetsOptionalProperties(): void
    {
        $completedAt = new DateTimeImmutable('2026-01-15');

        $request = new DsarRequest(
            id: 'req-xyz',
            subjectId: 'user-456',
            email: 'admin@example.com',
            status: DsarStatus::Completed,
            createdAt: new DateTimeImmutable('2026-01-01'),
            deadline: new DateTimeImmutable('2026-01-31'),
            completedAt: $completedAt,
            packagePath: '/data/dsar/req-xyz.zip',
            verificationToken: 'token-abc123',
        );

        self::assertSame($completedAt, $request->completedAt);
        self::assertSame('/data/dsar/req-xyz.zip', $request->packagePath);
        self::assertSame('token-abc123', $request->verificationToken);
    }

    #[Test]
    public function isOverdueReturnsTrueForPastDeadline(): void
    {
        $request = new DsarRequest(
            id: 'req-1',
            subjectId: 'sub-1',
            email: 'a@b.com',
            status: DsarStatus::Pending,
            createdAt: new DateTimeImmutable('-40 days'),
            deadline: new DateTimeImmutable('-10 days'),
        );

        self::assertTrue($request->isOverdue());
    }

    #[Test]
    public function isOverdueReturnsFalseForFutureDeadline(): void
    {
        $request = new DsarRequest(
            id: 'req-2',
            subjectId: 'sub-2',
            email: 'b@c.com',
            status: DsarStatus::Processing,
            createdAt: new DateTimeImmutable('-5 days'),
            deadline: new DateTimeImmutable('+25 days'),
        );

        self::assertFalse($request->isOverdue());
    }

    #[Test]
    public function isOverdueReturnsFalseForCompletedRequests(): void
    {
        $request = new DsarRequest(
            id: 'req-3',
            subjectId: 'sub-3',
            email: 'c@d.com',
            status: DsarStatus::Completed,
            createdAt: new DateTimeImmutable('-40 days'),
            deadline: new DateTimeImmutable('-10 days'),
            completedAt: new DateTimeImmutable('-11 days'),
        );

        self::assertFalse($request->isOverdue());
    }

    #[Test]
    public function isOverdueReturnsFalseForRejectedRequests(): void
    {
        $request = new DsarRequest(
            id: 'req-4',
            subjectId: 'sub-4',
            email: 'd@e.com',
            status: DsarStatus::Rejected,
            createdAt: new DateTimeImmutable('-40 days'),
            deadline: new DateTimeImmutable('-10 days'),
        );

        self::assertFalse($request->isOverdue());
    }

    #[Test]
    public function remainingDaysReturnsPositiveForFutureDeadline(): void
    {
        $request = new DsarRequest(
            id: 'req-5',
            subjectId: 'sub-5',
            email: 'e@f.com',
            status: DsarStatus::Pending,
            createdAt: new DateTimeImmutable(),
            deadline: new DateTimeImmutable('+20 days'),
        );

        $remaining = $request->remainingDays();
        self::assertGreaterThanOrEqual(19, $remaining);
        self::assertLessThanOrEqual(21, $remaining);
    }

    #[Test]
    public function remainingDaysReturnsNegativeForPastDeadline(): void
    {
        $request = new DsarRequest(
            id: 'req-6',
            subjectId: 'sub-6',
            email: 'f@g.com',
            status: DsarStatus::Pending,
            createdAt: new DateTimeImmutable('-40 days'),
            deadline: new DateTimeImmutable('-10 days'),
        );

        $remaining = $request->remainingDays();
        self::assertLessThan(0, $remaining);
        self::assertGreaterThanOrEqual(-11, $remaining);
        self::assertLessThanOrEqual(-9, $remaining);
    }

    #[Test]
    public function processingRequestPastDeadlineIsOverdue(): void
    {
        $request = new DsarRequest(
            id: 'req-7',
            subjectId: 'sub-7',
            email: 'g@h.com',
            status: DsarStatus::Processing,
            createdAt: new DateTimeImmutable('-35 days'),
            deadline: new DateTimeImmutable('-5 days'),
        );

        self::assertTrue($request->isOverdue());
    }
}
