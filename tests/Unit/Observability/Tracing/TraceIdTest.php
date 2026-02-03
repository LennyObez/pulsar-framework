<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\Tracing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\Tracing\Exception\TracingException;
use Pulsar\Observability\Tracing\TraceId;

use function strlen;

#[CoversClass(TraceId::class)]
final class TraceIdTest extends TestCase
{
    #[Test]
    public function generateProducesValid32HexChars(): void
    {
        $id = TraceId::generate();

        self::assertSame(32, strlen($id->value));
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id->value);
    }

    #[Test]
    public function normalizesToLowercase(): void
    {
        $id = new TraceId('4BF92F3577B34DA6A3CE929D0E0E4736');

        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $id->value);
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', (string) $id);
    }

    #[Test]
    public function rejectsInvalidTraceId(): void
    {
        $this->expectException(TracingException::class);
        $this->expectExceptionMessage('Invalid trace ID');

        new TraceId('not-valid');
    }

    #[Test]
    public function toStringReturnsValue(): void
    {
        $id = new TraceId('4bf92f3577b34da6a3ce929d0e0e4736');

        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $id->toString());
    }
}
