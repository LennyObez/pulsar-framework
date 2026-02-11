<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Grpc\Error;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Error\GrpcStatus;

use function in_array;

#[CoversClass(GrpcStatus::class)]
final class GrpcStatusFullCoverageTest extends TestCase
{
    #[Test]
    public function allHttpStatusCodeMappings(): void
    {
        self::assertSame(200, GrpcStatus::Ok->httpStatusCode());
        self::assertSame(499, GrpcStatus::Cancelled->httpStatusCode());
        self::assertSame(400, GrpcStatus::InvalidArgument->httpStatusCode());
        self::assertSame(504, GrpcStatus::DeadlineExceeded->httpStatusCode());
        self::assertSame(404, GrpcStatus::NotFound->httpStatusCode());
        self::assertSame(409, GrpcStatus::AlreadyExists->httpStatusCode());
        self::assertSame(403, GrpcStatus::PermissionDenied->httpStatusCode());
        self::assertSame(429, GrpcStatus::ResourceExhausted->httpStatusCode());
        self::assertSame(400, GrpcStatus::FailedPrecondition->httpStatusCode());
        self::assertSame(409, GrpcStatus::Aborted->httpStatusCode());
        self::assertSame(400, GrpcStatus::OutOfRange->httpStatusCode());
        self::assertSame(501, GrpcStatus::Unimplemented->httpStatusCode());
        self::assertSame(500, GrpcStatus::Internal->httpStatusCode());
        self::assertSame(503, GrpcStatus::Unavailable->httpStatusCode());
        self::assertSame(500, GrpcStatus::DataLoss->httpStatusCode());
        self::assertSame(401, GrpcStatus::Unauthenticated->httpStatusCode());
        self::assertSame(500, GrpcStatus::Unknown->httpStatusCode());
    }

    #[Test]
    public function allRetryableCases(): void
    {
        foreach (GrpcStatus::cases() as $status) {
            $expected = in_array($status, [
                GrpcStatus::DeadlineExceeded,
                GrpcStatus::ResourceExhausted,
                GrpcStatus::Unavailable,
                GrpcStatus::Aborted,
            ], true);

            self::assertSame($expected, $status->isRetryable(), "isRetryable for {$status->name}");
        }
    }

    #[Test]
    public function isOkOnlyForOkStatus(): void
    {
        foreach (GrpcStatus::cases() as $status) {
            if ($status === GrpcStatus::Ok) {
                self::assertTrue($status->isOk());
            } else {
                self::assertFalse($status->isOk(), "isOk should be false for {$status->name}");
            }
        }
    }
}
