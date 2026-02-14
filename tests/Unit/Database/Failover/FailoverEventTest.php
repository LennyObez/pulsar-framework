<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Failover;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Failover\FailoverEvent;
use ReflectionClass;

#[CoversClass(FailoverEvent::class)]
final class FailoverEventTest extends TestCase
{
    #[Test]
    public function constructionAndProperties(): void
    {
        $occurredAt = new DateTimeImmutable('2025-01-15T10:30:00+00:00');

        $event = new FailoverEvent(
            reason: 'Primary health check failure',
            sourceEndpoint: '10.0.0.1',
            targetEndpoint: '10.0.0.2',
            affectedOperationCount: 5,
            durationMs: 123.45,
            correlationId: 'corr-001',
            occurredAt: $occurredAt,
        );

        self::assertSame('Primary health check failure', $event->reason);
        self::assertSame('10.0.0.1', $event->sourceEndpoint);
        self::assertSame('10.0.0.2', $event->targetEndpoint);
        self::assertSame(5, $event->affectedOperationCount);
        self::assertSame(123.45, $event->durationMs);
        self::assertSame('corr-001', $event->correlationId);
        self::assertSame($occurredAt, $event->occurredAt);
    }

    #[Test]
    public function immutableReadonly(): void
    {
        $event = new FailoverEvent(
            reason: 'Test',
            sourceEndpoint: 'a',
            targetEndpoint: 'b',
            affectedOperationCount: 0,
            durationMs: 0.0,
            correlationId: 'id',
            occurredAt: new DateTimeImmutable(),
        );

        $reflection = new ReflectionClass($event);
        self::assertTrue($reflection->isReadOnly());
    }
}
