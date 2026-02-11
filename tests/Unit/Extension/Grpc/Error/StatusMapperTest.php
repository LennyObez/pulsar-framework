<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Grpc\Error;

use InvalidArgumentException;
use LogicException;
use OverflowException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Error\GrpcException;
use Pulsar\Extension\Grpc\Error\GrpcStatus;
use Pulsar\Extension\Grpc\Error\StatusMapper;
use RuntimeException;
use TypeError;

#[CoversClass(StatusMapper::class)]
final class StatusMapperTest extends TestCase
{
    #[Test]
    public function mapsGrpcExceptionToItsOwnStatus(): void
    {
        $exception = new GrpcException(GrpcStatus::PermissionDenied, 'Forbidden');
        self::assertSame(GrpcStatus::PermissionDenied, StatusMapper::fromException($exception));
    }

    #[Test]
    public function mapsInvalidArgumentException(): void
    {
        self::assertSame(
            GrpcStatus::InvalidArgument,
            StatusMapper::fromException(new InvalidArgumentException('Bad arg')),
        );
    }

    #[Test]
    public function mapsOverflowException(): void
    {
        self::assertSame(
            GrpcStatus::ResourceExhausted,
            StatusMapper::fromException(new OverflowException('Too much')),
        );
    }

    #[Test]
    public function mapsLogicException(): void
    {
        self::assertSame(
            GrpcStatus::FailedPrecondition,
            StatusMapper::fromException(new LogicException('Logic error')),
        );
    }

    #[Test]
    public function mapsRuntimeException(): void
    {
        self::assertSame(
            GrpcStatus::Internal,
            StatusMapper::fromException(new RuntimeException('Runtime failure')),
        );
    }

    #[Test]
    public function mapsUnknownExceptionToUnknown(): void
    {
        self::assertSame(
            GrpcStatus::Unknown,
            StatusMapper::fromException(new TypeError('Type error')),
        );
    }
}
