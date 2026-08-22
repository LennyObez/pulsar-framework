<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Grpc\Error;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Error\GrpcException;
use Pulsar\Extension\Grpc\Error\GrpcStatus;
use RuntimeException;

#[CoversClass(GrpcException::class)]
final class GrpcExceptionFactoriesTest extends TestCase
{
    #[Test]
    public function unimplementedFactory(): void
    {
        $exception = GrpcException::unimplemented('Not yet available');
        self::assertSame(GrpcStatus::Unimplemented, $exception->status);
        self::assertSame('Not yet available', $exception->getMessage());
    }

    #[Test]
    public function unimplementedFactoryWithDefault(): void
    {
        $exception = GrpcException::unimplemented();
        self::assertSame('Method not implemented', $exception->getMessage());
    }

    #[Test]
    public function deadlineExceededFactory(): void
    {
        $exception = GrpcException::deadlineExceeded('Timed out after 30s');
        self::assertSame(GrpcStatus::DeadlineExceeded, $exception->status);
        self::assertSame('Timed out after 30s', $exception->getMessage());
    }

    #[Test]
    public function deadlineExceededFactoryWithDefault(): void
    {
        $exception = GrpcException::deadlineExceeded();
        self::assertSame('Deadline exceeded', $exception->getMessage());
    }

    #[Test]
    public function resourceExhaustedFactory(): void
    {
        $exception = GrpcException::resourceExhausted('Rate limit exceeded');
        self::assertSame(GrpcStatus::ResourceExhausted, $exception->status);
        self::assertSame('Rate limit exceeded', $exception->getMessage());
    }

    #[Test]
    public function resourceExhaustedFactoryWithDefault(): void
    {
        $exception = GrpcException::resourceExhausted();
        self::assertSame('Resource exhausted', $exception->getMessage());
    }

    #[Test]
    public function constructionWithPreviousException(): void
    {
        $previous = new RuntimeException('Original error');
        $exception = new GrpcException(GrpcStatus::Internal, 'Wrapped error', [], $previous);

        self::assertSame($previous, $exception->getPrevious());
        self::assertSame(GrpcStatus::Internal->value, $exception->getCode());
    }
}
