<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Grpc\Error;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Error\GrpcStatus;
use Pulsar\Extension\Grpc\Error\StatusDetail;

#[CoversClass(StatusDetail::class)]
final class StatusDetailTest extends TestCase
{
    #[Test]
    public function constructionWithAllFields(): void
    {
        $detail = new StatusDetail(
            code: GrpcStatus::InvalidArgument,
            message: 'Email is required',
            details: ['field' => 'email'],
        );

        self::assertSame(GrpcStatus::InvalidArgument, $detail->code);
        self::assertSame('Email is required', $detail->message);
        self::assertSame(['field' => 'email'], $detail->details);
    }

    #[Test]
    public function fromArrayWithValidData(): void
    {
        $detail = StatusDetail::fromArray([
            'code' => 3,
            'message' => 'Invalid argument',
            'details' => ['field' => 'name'],
        ]);

        self::assertSame(GrpcStatus::InvalidArgument, $detail->code);
        self::assertSame('Invalid argument', $detail->message);
        self::assertSame(['field' => 'name'], $detail->details);
    }

    #[Test]
    public function fromArrayWithDefaults(): void
    {
        $detail = StatusDetail::fromArray([]);

        self::assertSame(GrpcStatus::Unknown, $detail->code);
        self::assertSame('', $detail->message);
        self::assertSame([], $detail->details);
    }

    #[Test]
    public function fromArrayWithNonStringMessage(): void
    {
        $detail = StatusDetail::fromArray(['message' => 42]);
        self::assertSame('42', $detail->message);
    }

    #[Test]
    public function fromArrayWithNonNumericCode(): void
    {
        $detail = StatusDetail::fromArray(['code' => 'invalid']);
        // (int) 'invalid' === 0, and GrpcStatus::from(0) === GrpcStatus::Ok
        self::assertSame(GrpcStatus::Ok, $detail->code);
    }

    #[Test]
    public function fromArrayWithNonArrayDetails(): void
    {
        $detail = StatusDetail::fromArray(['details' => 'not an array']);
        // extractDetails() returns [] when 'details' is not an array
        self::assertSame([], $detail->details);
    }

    #[Test]
    public function toArrayRoundTrip(): void
    {
        $original = new StatusDetail(
            code: GrpcStatus::NotFound,
            message: 'Resource missing',
            details: ['id' => '12345'],
        );

        $array = $original->toArray();

        self::assertSame(5, $array['code']);
        self::assertSame('Resource missing', $array['message']);
        self::assertSame(['id' => '12345'], $array['details']);
    }
}
