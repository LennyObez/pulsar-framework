<?php

declare(strict_types=1);

namespace Pulsar\Extension\DataAct\Tests\Unit\Portability;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\DataAct\Portability\ExportRequest;
use Pulsar\Extension\DataAct\Portability\ExportStatus;

#[CoversClass(ExportRequest::class)]
final class ExportRequestTest extends TestCase
{
    #[Test]
    public function pendingRequestProperties(): void
    {
        $now = new DateTimeImmutable();
        $deadline = new DateTimeImmutable('+30 days');

        $request = new ExportRequest(
            id: 'req-001',
            userId: 'user-42',
            format: 'json',
            scopes: ['profile', 'orders'],
            status: ExportStatus::Pending,
            requestedAt: $now,
            deadline: $deadline,
        );

        self::assertSame('req-001', $request->id);
        self::assertSame('user-42', $request->userId);
        self::assertSame('json', $request->format);
        self::assertSame(['profile', 'orders'], $request->scopes);
        self::assertSame(ExportStatus::Pending, $request->status);
        self::assertNull($request->dataPath);
        self::assertNull($request->fulfilledAt);
    }

    #[Test]
    public function isPendingReturnsTrueForPendingStatus(): void
    {
        $request = $this->createRequest(ExportStatus::Pending);

        self::assertTrue($request->isPending());
        self::assertFalse($request->isFulfilled());
    }

    #[Test]
    public function isFulfilledReturnsTrueForFulfilledStatus(): void
    {
        $request = $this->createRequest(ExportStatus::Fulfilled);

        self::assertFalse($request->isPending());
        self::assertTrue($request->isFulfilled());
    }

    #[Test]
    public function isOverdueReturnsTrueForExpiredPendingRequest(): void
    {
        $request = new ExportRequest(
            id: 'req-overdue',
            userId: 'user-1',
            format: 'json',
            scopes: [],
            status: ExportStatus::Pending,
            requestedAt: new DateTimeImmutable('-60 days'),
            deadline: new DateTimeImmutable('-1 day'),
        );

        self::assertTrue($request->isOverdue());
    }

    #[Test]
    public function isOverdueReturnsFalseForFulfilledRequest(): void
    {
        $request = new ExportRequest(
            id: 'req-done',
            userId: 'user-1',
            format: 'json',
            scopes: [],
            status: ExportStatus::Fulfilled,
            requestedAt: new DateTimeImmutable('-60 days'),
            deadline: new DateTimeImmutable('-1 day'),
            dataPath: '/exports/data.json',
            fulfilledAt: new DateTimeImmutable('-2 days'),
        );

        self::assertFalse($request->isOverdue());
    }

    #[Test]
    public function isOverdueReturnsFalseForFutureDeadline(): void
    {
        $request = $this->createRequest(ExportStatus::Pending);

        self::assertFalse($request->isOverdue());
    }

    private function createRequest(ExportStatus $status): ExportRequest
    {
        return new ExportRequest(
            id: 'req-test',
            userId: 'user-1',
            format: 'json',
            scopes: [],
            status: $status,
            requestedAt: new DateTimeImmutable(),
            deadline: new DateTimeImmutable('+30 days'),
        );
    }
}
