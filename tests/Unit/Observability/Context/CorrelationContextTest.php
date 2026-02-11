<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\Context;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\Context\CorrelationContext;

#[CoversClass(CorrelationContext::class)]
final class CorrelationContextTest extends TestCase
{
    #[Test]
    public function defaultsToNull(): void
    {
        $ctx = new CorrelationContext();

        self::assertNull($ctx->requestId);
        self::assertNull($ctx->traceId);
        self::assertNull($ctx->spanId);
        self::assertNull($ctx->jobId);
    }

    #[Test]
    public function holdsAllValues(): void
    {
        $ctx = new CorrelationContext(
            requestId: 'req-abc-123',
            traceId: '0af7651916cd43dd8448eb211c80319c',
            spanId: '00f067aa0ba902b7',
            jobId: 'job-456',
        );

        self::assertSame('req-abc-123', $ctx->requestId);
        self::assertSame('0af7651916cd43dd8448eb211c80319c', $ctx->traceId);
        self::assertSame('00f067aa0ba902b7', $ctx->spanId);
        self::assertSame('job-456', $ctx->jobId);
    }
}
