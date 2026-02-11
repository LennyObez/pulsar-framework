<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Grpc\Error;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Error\GrpcException;
use Pulsar\Extension\Grpc\Error\GrpcStatus;

#[CoversClass(GrpcException::class)]
#[CoversClass(GrpcStatus::class)]
final class GrpcErrorTest extends TestCase
{
    // --- GrpcStatus ---

    #[Test]
    public function statusIsOk(): void
    {
        self::assertTrue(GrpcStatus::Ok->isOk());
        self::assertFalse(GrpcStatus::NotFound->isOk());
        self::assertFalse(GrpcStatus::Internal->isOk());
    }

    #[Test]
    public function statusIsRetryable(): void
    {
        self::assertTrue(GrpcStatus::DeadlineExceeded->isRetryable());
        self::assertTrue(GrpcStatus::ResourceExhausted->isRetryable());
        self::assertTrue(GrpcStatus::Unavailable->isRetryable());
        self::assertTrue(GrpcStatus::Aborted->isRetryable());
        self::assertFalse(GrpcStatus::NotFound->isRetryable());
        self::assertFalse(GrpcStatus::PermissionDenied->isRetryable());
        self::assertFalse(GrpcStatus::Ok->isRetryable());
    }

    #[Test]
    public function statusHttpStatusCode(): void
    {
        self::assertSame(200, GrpcStatus::Ok->httpStatusCode());
        self::assertSame(404, GrpcStatus::NotFound->httpStatusCode());
        self::assertSame(500, GrpcStatus::Internal->httpStatusCode());
        self::assertSame(401, GrpcStatus::Unauthenticated->httpStatusCode());
        self::assertSame(403, GrpcStatus::PermissionDenied->httpStatusCode());
        self::assertSame(400, GrpcStatus::InvalidArgument->httpStatusCode());
        self::assertSame(503, GrpcStatus::Unavailable->httpStatusCode());
    }

    // --- GrpcException ---

    #[Test]
    public function constructionWithStatus(): void
    {
        $exception = new GrpcException(GrpcStatus::NotFound, 'User not found');

        self::assertSame(GrpcStatus::NotFound, $exception->status);
        self::assertSame('User not found', $exception->getMessage());
        self::assertSame(5, $exception->getCode());
        self::assertSame([], $exception->details);
    }

    #[Test]
    public function constructionWithDefaultMessage(): void
    {
        $exception = new GrpcException(GrpcStatus::Internal);

        self::assertSame('Internal', $exception->getMessage());
    }

    #[Test]
    public function constructionWithDetails(): void
    {
        $details = ['field' => 'email', 'violations' => ['invalid format']];
        $exception = new GrpcException(GrpcStatus::InvalidArgument, 'Bad request', $details);

        self::assertSame($details, $exception->details);
    }

    #[Test]
    public function notFoundFactory(): void
    {
        $exception = GrpcException::notFound('Account 12345 not found');

        self::assertSame(GrpcStatus::NotFound, $exception->status);
        self::assertSame('Account 12345 not found', $exception->getMessage());
    }

    #[Test]
    public function invalidArgumentFactory(): void
    {
        $exception = GrpcException::invalidArgument('Field email is required');

        self::assertSame(GrpcStatus::InvalidArgument, $exception->status);
    }

    #[Test]
    public function unauthenticatedFactory(): void
    {
        $exception = GrpcException::unauthenticated('Token expired');

        self::assertSame(GrpcStatus::Unauthenticated, $exception->status);
    }

    #[Test]
    public function permissionDeniedFactory(): void
    {
        $exception = GrpcException::permissionDenied('Insufficient privileges');

        self::assertSame(GrpcStatus::PermissionDenied, $exception->status);
    }

    #[Test]
    public function internalFactory(): void
    {
        $exception = GrpcException::internal('Database connection failed');

        self::assertSame(GrpcStatus::Internal, $exception->status);
    }

    #[Test]
    public function unavailableFactory(): void
    {
        $exception = GrpcException::unavailable('Service maintenance');

        self::assertSame(GrpcStatus::Unavailable, $exception->status);
    }

    #[Test]
    public function factoryWithDefaultMessage(): void
    {
        self::assertSame('Not found', GrpcException::notFound()->getMessage());
        self::assertSame('Invalid argument', GrpcException::invalidArgument()->getMessage());
        self::assertSame('Unauthenticated', GrpcException::unauthenticated()->getMessage());
        self::assertSame('Permission denied', GrpcException::permissionDenied()->getMessage());
        self::assertSame('Internal error', GrpcException::internal()->getMessage());
        self::assertSame('Service unavailable', GrpcException::unavailable()->getMessage());
    }
}
