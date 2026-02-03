<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\Tracing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\Tracing\Exception\TracingException;
use Pulsar\Observability\Tracing\SpanId;

use function strlen;

#[CoversClass(SpanId::class)]
final class SpanIdTest extends TestCase
{
    #[Test]
    public function generateProducesValid16HexChars(): void
    {
        $id = SpanId::generate();

        self::assertSame(16, strlen($id->value));
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $id->value);
    }

    #[Test]
    public function normalizesToLowercase(): void
    {
        $id = new SpanId('00F067AA0BA902B7');

        self::assertSame('00f067aa0ba902b7', $id->value);
        self::assertSame('00f067aa0ba902b7', (string) $id);
    }

    #[Test]
    public function rejectsInvalidSpanId(): void
    {
        $this->expectException(TracingException::class);
        $this->expectExceptionMessage('Invalid span ID');

        new SpanId('invalid');
    }
}
