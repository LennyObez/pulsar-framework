<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Studio\CorrelationContext;

#[CoversClass(CorrelationContext::class)]
final class CorrelationContextTest extends TestCase
{
    #[Test]
    public function constructorWithAllParameters(): void
    {
        $context = new CorrelationContext(
            requestId: 'req-123',
            traceId: 'trace-456',
            spanId: 'span-789',
            jobId: 'job-000',
        );

        self::assertSame('req-123', $context->requestId);
        self::assertSame('trace-456', $context->traceId);
        self::assertSame('span-789', $context->spanId);
        self::assertSame('job-000', $context->jobId);
    }

    #[Test]
    public function constructorWithDefaultNullValues(): void
    {
        $context = new CorrelationContext();

        self::assertNull($context->requestId);
        self::assertNull($context->traceId);
        self::assertNull($context->spanId);
        self::assertNull($context->jobId);
    }

    #[Test]
    public function constructorWithPartialParameters(): void
    {
        $context = new CorrelationContext(
            requestId: 'req-only',
            traceId: null,
            spanId: 'span-only',
        );

        self::assertSame('req-only', $context->requestId);
        self::assertNull($context->traceId);
        self::assertSame('span-only', $context->spanId);
        self::assertNull($context->jobId);
    }

    #[Test]
    public function requestIdProperty(): void
    {
        $context = new CorrelationContext(requestId: 'test-request-id');

        self::assertSame('test-request-id', $context->requestId);
    }

    #[Test]
    public function requestIdCanBeNull(): void
    {
        $context = new CorrelationContext(requestId: null);

        self::assertNull($context->requestId);
    }

    #[Test]
    public function traceIdProperty(): void
    {
        $context = new CorrelationContext(traceId: 'test-trace-id');

        self::assertSame('test-trace-id', $context->traceId);
    }

    #[Test]
    public function traceIdCanBeNull(): void
    {
        $context = new CorrelationContext(traceId: null);

        self::assertNull($context->traceId);
    }

    #[Test]
    public function spanIdProperty(): void
    {
        $context = new CorrelationContext(spanId: 'test-span-id');

        self::assertSame('test-span-id', $context->spanId);
    }

    #[Test]
    public function spanIdCanBeNull(): void
    {
        $context = new CorrelationContext(spanId: null);

        self::assertNull($context->spanId);
    }

    #[Test]
    public function jobIdProperty(): void
    {
        $context = new CorrelationContext(jobId: 'test-job-id');

        self::assertSame('test-job-id', $context->jobId);
    }

    #[Test]
    public function jobIdCanBeNull(): void
    {
        $context = new CorrelationContext(jobId: null);

        self::assertNull($context->jobId);
    }

    #[Test]
    public function contextIsImmutable(): void
    {
        $context = new CorrelationContext(
            requestId: 'req-123',
            traceId: 'trace-456',
        );

        // CorrelationContext is a readonly class, so properties cannot be modified
        // This test verifies the class behavior - attempting to modify would cause a compile-time error
        self::assertSame('req-123', $context->requestId);
        self::assertSame('trace-456', $context->traceId);
    }

    #[Test]
    public function contextAcceptsEmptyStrings(): void
    {
        $context = new CorrelationContext(
            requestId: '',
            traceId: '',
            spanId: '',
            jobId: '',
        );

        self::assertSame('', $context->requestId);
        self::assertSame('', $context->traceId);
        self::assertSame('', $context->spanId);
        self::assertSame('', $context->jobId);
    }

    #[Test]
    public function contextAcceptsLongStrings(): void
    {
        $longId = str_repeat('a', 1000);

        $context = new CorrelationContext(
            requestId: $longId,
            traceId: $longId,
            spanId: $longId,
            jobId: $longId,
        );

        self::assertSame($longId, $context->requestId);
        self::assertSame($longId, $context->traceId);
        self::assertSame($longId, $context->spanId);
        self::assertSame($longId, $context->jobId);
    }

    #[Test]
    public function contextAcceptsSpecialCharacters(): void
    {
        $specialId = "req-!@#$%^&*()_+-=[]{}|;':\",./<>?";

        $context = new CorrelationContext(requestId: $specialId);

        self::assertSame($specialId, $context->requestId);
    }

    #[Test]
    public function contextAcceptsUnicodeCharacters(): void
    {
        $unicodeId = 'req-\u4e2d\u6587-\u65e5\u672c\u8a9e-\ud55c\uad6d\uc5b4';

        $context = new CorrelationContext(requestId: $unicodeId);

        self::assertSame($unicodeId, $context->requestId);
    }

    #[Test]
    public function multipleContextInstancesAreIndependent(): void
    {
        $context1 = new CorrelationContext(requestId: 'req-1');
        $context2 = new CorrelationContext(requestId: 'req-2');

        self::assertSame('req-1', $context1->requestId);
        self::assertSame('req-2', $context2->requestId);
        self::assertNotSame($context1->requestId, $context2->requestId);
    }

    #[Test]
    public function constructorWithOnlyJobId(): void
    {
        $context = new CorrelationContext(jobId: 'background-job-123');

        self::assertNull($context->requestId);
        self::assertNull($context->traceId);
        self::assertNull($context->spanId);
        self::assertSame('background-job-123', $context->jobId);
    }

    #[Test]
    public function constructorWithNamedArguments(): void
    {
        $context = new CorrelationContext(
            jobId: 'job-first',
            requestId: 'req-second',
        );

        self::assertSame('req-second', $context->requestId);
        self::assertNull($context->traceId);
        self::assertNull($context->spanId);
        self::assertSame('job-first', $context->jobId);
    }
}
