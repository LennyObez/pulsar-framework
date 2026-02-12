<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Tests\Unit\Internal\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Internal\Transport\TransportResult;

#[CoversClass(TransportResult::class)]
final class TransportResultTest extends TestCase
{
    #[Test]
    public function successFactory(): void
    {
        $result = TransportResult::success(200);

        self::assertTrue($result->success);
        self::assertSame(200, $result->httpStatus);
        self::assertSame('', $result->errorMessage);
        self::assertFalse($result->retryable);
    }

    #[Test]
    public function successFactoryWith204(): void
    {
        $result = TransportResult::success(204);

        self::assertTrue($result->success);
        self::assertSame(204, $result->httpStatus);
    }

    #[Test]
    public function failureFactoryNonRetryable(): void
    {
        $result = TransportResult::failure(400, 'Bad Request');

        self::assertFalse($result->success);
        self::assertSame(400, $result->httpStatus);
        self::assertSame('Bad Request', $result->errorMessage);
        self::assertFalse($result->retryable);
    }

    #[Test]
    public function failureFactoryRetryable(): void
    {
        $result = TransportResult::failure(503, 'Service Unavailable', retryable: true);

        self::assertFalse($result->success);
        self::assertSame(503, $result->httpStatus);
        self::assertSame('Service Unavailable', $result->errorMessage);
        self::assertTrue($result->retryable);
    }

    #[Test]
    public function failureFactoryDefaultNotRetryable(): void
    {
        $result = TransportResult::failure(500, 'Internal Server Error');

        self::assertFalse($result->retryable);
    }

    #[Test]
    public function constructorDirectly(): void
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
