<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\Tracing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\Tracing\SpanId;
use Pulsar\Observability\Tracing\TraceContext;
use Pulsar\Observability\Tracing\TraceId;
use Pulsar\Observability\Tracing\W3CTraceContextParser;

#[CoversClass(W3CTraceContextParser::class)]
final class W3CTraceContextParserTest extends TestCase
{
    private W3CTraceContextParser $parser;

    protected function setUp(): void
    {
        $this->parser = new W3CTraceContextParser();
    }

    #[Test]
    public function parsesValidTraceparent(): void
    {
        $header = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';
        $ctx = $this->parser->parse($header);

        self::assertNotNull($ctx);
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $ctx->traceId->value);
        self::assertSame('00f067aa0ba902b7', $ctx->spanId->value);
        self::assertSame(1, $ctx->traceFlags);
        self::assertTrue($ctx->isSampled());
    }

    #[Test]
    public function returnsNullForMalformedHeader(): void
    {
        self::assertNull($this->parser->parse('invalid'));
        self::assertNull($this->parser->parse('00-short-short-01'));
        self::assertNull($this->parser->parse(''));
    }

    #[Test]
    public function returnsNullForAllZeroTraceId(): void
    {
        $header = '00-00000000000000000000000000000000-00f067aa0ba902b7-01';

        self::assertNull($this->parser->parse($header));
    }

    #[Test]
    public function returnsNullForAllZeroSpanId(): void
    {
        $header = '00-4bf92f3577b34da6a3ce929d0e0e4736-0000000000000000-01';

        self::assertNull($this->parser->parse($header));
    }

    #[Test]
    public function serializesTraceContext(): void
    {
        $ctx = new TraceContext(
            traceId: new TraceId('4bf92f3577b34da6a3ce929d0e0e4736'),
            spanId: new SpanId('00f067aa0ba902b7'),
            traceFlags: 0x01,
        );

        $serialized = $this->parser->serialize($ctx);

        self::assertSame('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01', $serialized);
    }
}
