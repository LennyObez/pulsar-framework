<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Console\Event\Payload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;
use Pulsar\Extension\Studio\Console\Event\Payload\RuntimeLeakWarningPayload;

#[CoversClass(RuntimeLeakWarningPayload::class)]
final class RuntimeLeakWarningPayloadTest extends TestCase
{
    #[Test]
    public function eventTypeReturnsRuntimeLeakWarning(): void
    {
        self::assertSame(EventType::RuntimeLeakWarning, $this->createPayload()->eventType());
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

        self::assertSame(['Memory growth detected', 'Unclosed stream'], $array['warnings']);
        self::assertSame(1048576, $array['memory_delta_bytes']);
        self::assertSame(42, $array['request_number']);
    }

    #[Test]
    public function emptyWarningsList(): void
    {
        $payload = new RuntimeLeakWarningPayload(
            warnings: [],
            memoryDeltaBytes: 0,
            requestNumber: 1,
        );

        self::assertSame([], $payload->toArray()['warnings']);
        self::assertSame(0, $payload->memoryDeltaBytes);
    }

    private function createPayload(): RuntimeLeakWarningPayload
    {
        return new RuntimeLeakWarningPayload(
            warnings: ['Memory growth detected', 'Unclosed stream'],
            memoryDeltaBytes: 1048576,
            requestNumber: 42,
        );
    }
}
