<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\Event\DlqJobDeleted;
use Pulsar\Queue\Event\DlqJobInspected;
use Pulsar\Queue\Event\DlqJobRetried;
use Pulsar\Queue\Event\DlqJobStored;

#[CoversClass(DlqJobDeleted::class)]
#[CoversClass(DlqJobInspected::class)]
#[CoversClass(DlqJobRetried::class)]
#[CoversClass(DlqJobStored::class)]
final class DlqEventTest extends TestCase
{
    #[Test]
    public function dlqJobDeletedHoldsAllProperties(): void
    {
        $event = new DlqJobDeleted(
            jobId: 'dlq-001',
            actorId: 'admin-42',
            reason: 'Duplicate entry resolved manually',
            correlationId: 'corr-abc-123',
            timestamp: 1709827200,
        );

        self::assertSame('dlq-001', $event->jobId);
        self::assertSame('admin-42', $event->actorId);
        self::assertSame('Duplicate entry resolved manually', $event->reason);
        self::assertSame('corr-abc-123', $event->correlationId);
        self::assertSame(1709827200, $event->timestamp);
    }

    #[Test]
    public function dlqJobInspectedHoldsAllProperties(): void
    {
        $event = new DlqJobInspected(
            jobId: 'dlq-002',
            actorId: 'ops-team',
            correlationId: 'corr-def-456',
            timestamp: 1709827300,
        );

        self::assertSame('dlq-002', $event->jobId);
        self::assertSame('ops-team', $event->actorId);
        self::assertSame('corr-def-456', $event->correlationId);
        self::assertSame(1709827300, $event->timestamp);
    }

    #[Test]
    public function dlqJobRetriedHoldsAllProperties(): void
    {
        $event = new DlqJobRetried(
            jobId: 'dlq-003',
            actorId: 'support-agent',
            correlationId: 'corr-ghi-789',
            timestamp: 1709827400,
        );

        self::assertSame('dlq-003', $event->jobId);
        self::assertSame('support-agent', $event->actorId);
        self::assertSame('corr-ghi-789', $event->correlationId);
        self::assertSame(1709827400, $event->timestamp);
    }

    #[Test]
    public function dlqJobStoredHoldsAllProperties(): void
    {
        $event = new DlqJobStored(
            jobId: 'dlq-004',
            queue: 'payments',
            jobClass: 'App\\Jobs\\ProcessRefund',
            reason: 'Max attempts exceeded after 5 retries',
            correlationId: 'corr-jkl-012',
            timestamp: 1709827500,
        );

        self::assertSame('dlq-004', $event->jobId);
        self::assertSame('payments', $event->queue);
        self::assertSame('App\\Jobs\\ProcessRefund', $event->jobClass);
        self::assertSame('Max attempts exceeded after 5 retries', $event->reason);
        self::assertSame('corr-jkl-012', $event->correlationId);
        self::assertSame(1709827500, $event->timestamp);
    }
}
