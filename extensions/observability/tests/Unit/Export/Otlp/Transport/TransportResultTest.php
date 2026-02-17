<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Tests\Unit\Export\Otlp\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Observability\Export\Otlp\Transport\TransportResult;

#[CoversClass(TransportResult::class)]
final class TransportResultTest extends TestCase
{
    #[Test]
    public function successFactoryCreatesSuccessfulResult(): void
    {
        $result = TransportResult::success(200);

        self::assertTrue($result->success);
        self::assertSame(200, $result->httpStatus);
        self::assertSame('', $result->errorMessage);
        self::assertFalse($result->retryable);
    }

    #[Test]
    public function failureFactoryCreatesFailedResult(): void
    {
        $result = TransportResult::failure(503, 'Service unavailable', true);

        self::assertFalse($result->success);
        self::assertSame(503, $result->httpStatus);
        self::assertSame('Service unavailable', $result->errorMessage);
        self::assertTrue($result->retryable);
    }

    #[Test]
    public function failureDefaultsToNonRetryable(): void
    {
        $result = TransportResult::failure(400, 'Bad request');

        self::assertFalse($result->retryable);
    }

    #[Test]
    public function constructorAcceptsAllParameters(): void
    {
        $result = new TransportResult(
            success: false,
            httpStatus: 429,
            errorMessage: 'Rate limited',
            retryable: true,
        );

        self::assertFalse($result->success);
        self::assertSame(429, $result->httpStatus);
        self::assertSame('Rate limited', $result->errorMessage);
        self::assertTrue($result->retryable);
    }
}
