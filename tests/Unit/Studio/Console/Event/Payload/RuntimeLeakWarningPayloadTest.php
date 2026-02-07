<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Console\Event\Payload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Studio\Console\Event\EventType;
use Pulsar\Studio\Console\Event\EventVersion;
use Pulsar\Studio\Console\Event\Payload\RuntimeLeakWarningPayload;

#[CoversClass(RuntimeLeakWarningPayload::class)]
final class RuntimeLeakWarningPayloadTest extends TestCase
{
    #[Test]
    public function it_returns_correct_event_type(): void
    {
        $payload = new RuntimeLeakWarningPayload(
            warnings: ['Unreleased resource: conn-1'],
            memoryDeltaBytes: 2_097_152,
            requestNumber: 42,
        );

        self::assertSame(EventType::RuntimeLeakWarning, $payload->eventType());
    }

    #[Test]
    public function it_returns_schema_version_v1(): void
    {
        $payload = new RuntimeLeakWarningPayload(
            warnings: [],
            memoryDeltaBytes: 0,
            requestNumber: 1,
        );

        self::assertSame(EventVersion::V1, $payload->schemaVersion());
    }

    #[Test]
    public function it_serializes_to_array(): void
    {
        $warnings = [
            'Unreleased resource: [database] conn-1',
            'Memory growth: 3MB',
        ];

        $payload = new RuntimeLeakWarningPayload(
            warnings: $warnings,
            memoryDeltaBytes: 3_145_728,
            requestNumber: 100,
        );

        $array = $payload->toArray();

        self::assertSame($warnings, $array['warnings']);
        self::assertSame(3_145_728, $array['memory_delta_bytes']);
        self::assertSame(100, $array['request_number']);
    }
}
