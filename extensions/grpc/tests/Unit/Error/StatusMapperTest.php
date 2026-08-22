<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Tests\Unit\Error;

use Exception;
use InvalidArgumentException;
use LogicException;
use OverflowException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Error\GrpcException;
use Pulsar\Extension\Grpc\Error\GrpcStatus;
use Pulsar\Extension\Grpc\Error\StatusMapper;
use RuntimeException;
use Throwable;
use UnderflowException;

#[CoversClass(StatusMapper::class)]
final class StatusMapperTest extends TestCase
{
    #[Test]
    public function grpcExceptionUsesItsOwnStatus(): void
    {
        $exception = new GrpcException(GrpcStatus::NotFound, 'not found');

        self::assertSame(GrpcStatus::NotFound, StatusMapper::fromException($exception));
    }

    #[Test]
    public function invalidArgumentExceptionMapsToInvalidArgument(): void
    {
        $exception = new InvalidArgumentException('bad input');

        self::assertSame(GrpcStatus::InvalidArgument, StatusMapper::fromException($exception));
    }

    #[Test]
    public function overflowExceptionMapsToResourceExhausted(): void
    {
        $exception = new OverflowException('too many');

        self::assertSame(GrpcStatus::ResourceExhausted, StatusMapper::fromException($exception));
    }

    #[Test]
    public function logicExceptionMapsToFailedPrecondition(): void
    {
        $exception = new LogicException('precondition failed');

        self::assertSame(GrpcStatus::FailedPrecondition, StatusMapper::fromException($exception));
    }

    #[Test]
    public function runtimeExceptionMapsToInternal(): void
    {
        $exception = new RuntimeException('internal error');

        self::assertSame(GrpcStatus::Internal, StatusMapper::fromException($exception));
    }

    #[Test]
    public function genericExceptionMapsToUnknown(): void
    {
        $exception = new Exception('something went wrong');

        self::assertSame(GrpcStatus::Unknown, StatusMapper::fromException($exception));
    }

    #[Test]
    public function underflowExceptionMapsToFailedPreconditionViaLogicException(): void
    {
        // UnderflowException extends RuntimeException
        $exception = new UnderflowException('underflow');

        self::assertSame(GrpcStatus::Internal, StatusMapper::fromException($exception));
    }

    #[Test]
    public function grpcExceptionTakesPriorityOverRuntimeException(): void
    {
        // GrpcException extends RuntimeException, but should use its own status
        $exception = new GrpcException(GrpcStatus::Unavailable, 'unavailable');

        self::assertSame(GrpcStatus::Unavailable, StatusMapper::fromException($exception));
    }

    /**
     * @return iterable<string, array{Throwable, GrpcStatus}>
     */
    public static function grpcExceptionStatusProvider(): iterable
    {
        foreach (GrpcStatus::cases() as $status) {
            yield $status->name => [new GrpcException($status), $status];
        }
    }

    #[Test]
    #[DataProvider('grpcExceptionStatusProvider')]
    public function grpcExceptionPreservesAllStatusCodes(Throwable $exception, GrpcStatus $expected): void
    {
        self::assertSame($expected, StatusMapper::fromException($exception));
    }
}
