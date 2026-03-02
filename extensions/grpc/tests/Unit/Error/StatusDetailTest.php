<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Tests\Unit\Error;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Error\GrpcStatus;
use Pulsar\Extension\Grpc\Error\StatusDetail;

#[CoversClass(StatusDetail::class)]
final class StatusDetailTest extends TestCase
{
    #[Test]
    public function constructsWithAllProperties(): void
    {
        $detail = new StatusDetail(
            code: GrpcStatus::InvalidArgument,
            message: 'Field "name" is required',
            details: ['field' => 'name', 'reason' => 'REQUIRED'],
        );

        self::assertSame(GrpcStatus::InvalidArgument, $detail->code);
        self::assertSame('Field "name" is required', $detail->message);
        self::assertSame(['field' => 'name', 'reason' => 'REQUIRED'], $detail->details);
    }

    #[Test]
    public function constructsWithDefaultEmptyDetails(): void
    {
        $detail = new StatusDetail(
            code: GrpcStatus::Internal,
            message: 'Unexpected error',
        );

        self::assertSame([], $detail->details);
    }

    #[Test]
    public function toArrayReturnsCorrectStructure(): void
    {
        $detail = new StatusDetail(
            code: GrpcStatus::NotFound,
            message: 'Resource not found',
            details: ['resource_type' => 'User', 'resource_id' => '42'],
        );

        $expected = [
            'code' => 5,
            'message' => 'Resource not found',
            'details' => ['resource_type' => 'User', 'resource_id' => '42'],
        ];

        self::assertSame($expected, $detail->toArray());
    }

    #[Test]
    public function fromArrayWithValidData(): void
    {
        $detail = StatusDetail::fromArray([
            'code' => 7,
            'message' => 'Permission denied',
            'details' => ['permission' => 'admin'],
        ]);

        self::assertSame(GrpcStatus::PermissionDenied, $detail->code);
        self::assertSame('Permission denied', $detail->message);
        self::assertSame(['permission' => 'admin'], $detail->details);
    }

    #[Test]
    public function fromArrayDefaultsToUnknownStatus(): void
    {
        $detail = StatusDetail::fromArray([]);

        self::assertSame(GrpcStatus::Unknown, $detail->code);
        self::assertSame('', $detail->message);
        self::assertSame([], $detail->details);
    }

    #[Test]
    public function fromArrayWithPartialData(): void
    {
        $detail = StatusDetail::fromArray(['code' => 13, 'message' => 'Internal']);

        self::assertSame(GrpcStatus::Internal, $detail->code);
        self::assertSame('Internal', $detail->message);
        self::assertSame([], $detail->details);
    }

    #[Test]
    public function roundTripPreservesData(): void
    {
        $original = new StatusDetail(
            code: GrpcStatus::ResourceExhausted,
            message: 'Rate limit exceeded',
            details: ['retry_after_seconds' => 30],
        );

        $restored = StatusDetail::fromArray($original->toArray());

        self::assertSame($original->code, $restored->code);
        self::assertSame($original->message, $restored->message);
        self::assertSame($original->details, $restored->details);
    }

    #[Test]
    public function fromArrayCastsTypes(): void
    {
        // Non-int code falls back to Unknown; non-string message falls back to ''
        $detail = StatusDetail::fromArray([
            'code' => '3',
            'message' => 123,
        ]);

        self::assertSame(GrpcStatus::Unknown, $detail->code);
        self::assertSame('', $detail->message);
    }
}
