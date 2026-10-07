<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Console\Event\Payload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;
use Pulsar\Extension\Studio\Console\Event\Payload\SupervisorPayload;

#[CoversClass(SupervisorPayload::class)]
final class SupervisorPayloadTest extends TestCase
{
    #[Test]
    public function eventTypeReturnsHeartbeat(): void
    {
        self::assertSame(EventType::Heartbeat, $this->createPayload()->eventType());
    }

    #[Test]
    public function schemaVersionReturnsV1(): void
    {
        self::assertSame(EventVersion::V1, $this->createPayload()->schemaVersion());
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $array = $this->createPayload()->toArray();

        self::assertSame('worker_recycle', $array['type']);
        self::assertSame('restart', $array['action']);
        self::assertTrue($array['success']);
        self::assertSame(['worker_id' => 3, 'reason' => 'memory'], $array['details']);
        self::assertSame(1700000000, $array['performed_at']);
    }

    #[Test]
    public function failedAction(): void
    {
        $payload = new SupervisorPayload(
            type: 'stuck_job_recovery',
            action: 'kill',
            success: false,
            details: ['job_id' => 'abc-123'],
            performedAt: 1700000001,
        );

        $array = $payload->toArray();

        self::assertFalse($array['success']);
        self::assertSame('stuck_job_recovery', $array['type']);
    }

    #[Test]
    public function emptyDetails(): void
    {
        $payload = new SupervisorPayload(
            type: 'cache_purge',
            action: 'clear',
            success: true,
            details: [],
            performedAt: 1700000000,
        );

        self::assertSame([], $payload->toArray()['details']);
    }

    private function createPayload(): SupervisorPayload
    {
        return new SupervisorPayload(
            type: 'worker_recycle',
            action: 'restart',
            success: true,
            details: ['worker_id' => 3, 'reason' => 'memory'],
            performedAt: 1700000000,
        );
    }
}
