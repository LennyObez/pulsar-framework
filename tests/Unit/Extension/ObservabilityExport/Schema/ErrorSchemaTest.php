<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\ObservabilityExport\Schema;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\ObservabilityExport\Schema\ErrorSchema;
use Pulsar\Observability\ErrorTracking\ErrorEvent;
use Pulsar\Observability\ErrorTracking\ErrorFingerprint;
use Pulsar\Observability\Tracing\TraceId;
use RuntimeException;

use function assert;
use function is_string;

#[CoversClass(ErrorSchema::class)]
final class ErrorSchemaTest extends TestCase
{
    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $event = new ErrorEvent(
            fingerprint: new ErrorFingerprint('abc123'),
            exceptionClass: RuntimeException::class,
            message: 'Something went wrong',
            file: '/app/src/Service.php',
            line: 42,
            stackTrace: [
                ['file' => '/app/src/Service.php', 'line' => 42, 'class' => 'App\\Service', 'function' => 'process'],
            ],
            context: ['request_id' => 'req-001'],
            occurredAt: new DateTimeImmutable('2025-01-15T12:30:45.123456+00:00', new DateTimeZone('UTC')),
            traceId: new TraceId('0af7651916cd43dd8448eb211c80319c'),
        );

        $data = ErrorSchema::toArray($event);

        self::assertSame('1.0.0', $data['schema_version']);
        self::assertSame('abc123', $data['fingerprint']);
        self::assertSame(RuntimeException::class, $data['exception_class']);
        self::assertSame('Something went wrong', $data['message']);
        self::assertSame('/app/src/Service.php', $data['file']);
        self::assertSame(42, $data['line']);
        self::assertIsArray($data['stack_trace']);
        self::assertCount(1, $data['stack_trace']);
        self::assertSame(['request_id' => 'req-001'], $data['context']);
        $occurredAt = $data['occurred_at'];
        assert(is_string($occurredAt));
        self::assertStringContainsString('2025-01-15', $occurredAt);
        self::assertSame('0af7651916cd43dd8448eb211c80319c', $data['trace_id']);
    }

    #[Test]
    public function toArrayWithNullTraceId(): void
    {
        $event = new ErrorEvent(
            fingerprint: new ErrorFingerprint('def456'),
            exceptionClass: RuntimeException::class,
            message: 'Error',
            file: '/app/src/Handler.php',
            line: 10,
            stackTrace: [],
            context: [],
            occurredAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
            traceId: null,
        );

        $data = ErrorSchema::toArray($event);

        self::assertNull($data['trace_id']);
    }

    #[Test]
    public function toJsonProducesValidJson(): void
    {
        $event = new ErrorEvent(
            fingerprint: new ErrorFingerprint('ghi789'),
            exceptionClass: RuntimeException::class,
            message: 'Test error with /path/here',
            file: '/app/src/Test.php',
            line: 1,
            stackTrace: [],
            context: [],
            occurredAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );

        $json = ErrorSchema::toJson($event);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('ghi789', $decoded['fingerprint']);
        // JSON_UNESCAPED_SLASHES should preserve /path/here
        self::assertStringNotContainsString('\\/', $json);
    }
}
