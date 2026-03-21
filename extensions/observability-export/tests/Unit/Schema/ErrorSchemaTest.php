<?php

declare(strict_types=1);

namespace Pulsar\Extension\ObservabilityExportTests\Unit\Schema;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\ObservabilityExport\Schema\ErrorSchema;
use Pulsar\Observability\ErrorTracking\ErrorEvent;
use Pulsar\Observability\ErrorTracking\ErrorFingerprint;
use Pulsar\Observability\Tracing\TraceId;

final class ErrorSchemaTest extends TestCase
{
    #[Test]
    public function toArrayIncludesSchemaVersion(): void
    {
        $event = $this->createEvent();
        $array = ErrorSchema::toArray($event);

        self::assertSame('1.0.0', $array['schema_version']);
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $event = $this->createEvent();
        $array = ErrorSchema::toArray($event);

        self::assertSame('abc123', $array['fingerprint']);
        self::assertSame('RuntimeException', $array['exception_class']);
        self::assertSame('Something broke', $array['message']);
        self::assertSame('/app/Foo.php', $array['file']);
        self::assertSame(42, $array['line']);
        self::assertSame([], $array['stack_trace']);
        self::assertSame(['key' => 'value'], $array['context']);
        /** @var string $occurredAt */
        $occurredAt = $array['occurred_at'];
        self::assertStringContainsString('2024-01-15', $occurredAt);
    }

    #[Test]
    public function toArrayIncludesNullTraceIdWhenAbsent(): void
    {
        $event = $this->createEvent();
        $array = ErrorSchema::toArray($event);

        self::assertNull($array['trace_id']);
    }

    #[Test]
    public function toArrayIncludesTraceIdWhenPresent(): void
    {
        $traceId = new TraceId('a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4');
        $event = $this->createEvent(traceId: $traceId);
        $array = ErrorSchema::toArray($event);

        self::assertSame('a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4', $array['trace_id']);
    }

    #[Test]
    public function toJsonReturnsValidJson(): void
    {
        $event = $this->createEvent();
        $json = ErrorSchema::toJson($event);

        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame('1.0.0', $decoded['schema_version']);
        self::assertSame('abc123', $decoded['fingerprint']);
    }

    #[Test]
    public function toJsonUsesUnescapedSlashes(): void
    {
        $event = $this->createEvent(file: '/app/src/Foo.php');
        $json = ErrorSchema::toJson($event);

        self::assertStringContainsString('/app/src/Foo.php', $json);
        self::assertStringNotContainsString('\\/app\\/src\\/Foo.php', $json);
    }

    private function createEvent(?TraceId $traceId = null, string $file = '/app/Foo.php'): ErrorEvent
    {
        return new ErrorEvent(
            fingerprint: new ErrorFingerprint('abc123'),
            exceptionClass: 'RuntimeException',
            message: 'Something broke',
            file: $file,
            line: 42,
            stackTrace: [],
            context: ['key' => 'value'],
            occurredAt: new DateTimeImmutable('2024-01-15 10:30:00', new DateTimeZone('UTC')),
            traceId: $traceId,
        );
    }
}
