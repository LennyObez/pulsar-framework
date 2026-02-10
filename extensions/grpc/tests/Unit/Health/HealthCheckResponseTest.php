<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Tests\Unit\Health;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Health\HealthCheckResponse;
use Pulsar\Extension\Grpc\Health\HealthStatus;

#[CoversClass(HealthCheckResponse::class)]
final class HealthCheckResponseTest extends TestCase
{
    #[Test]
    public function constructsWithStatus(): void
    {
        $response = new HealthCheckResponse(HealthStatus::Serving);

        self::assertSame(HealthStatus::Serving, $response->status);
    }

    #[Test]
    public function toArrayReturnsStatusValue(): void
    {
        $response = new HealthCheckResponse(HealthStatus::NotServing);

        self::assertSame(['status' => 2], $response->toArray());
    }

    #[Test]
    public function fromArrayWithValidStatus(): void
    {
        $response = HealthCheckResponse::fromArray(['status' => 1]);

        self::assertSame(HealthStatus::Serving, $response->status);
    }

    #[Test]
    public function fromArrayDefaultsToUnknown(): void
    {
        $response = HealthCheckResponse::fromArray([]);

        self::assertSame(HealthStatus::Unknown, $response->status);
    }

    #[Test]
    public function fromArrayWithEachStatus(): void
    {
        foreach (HealthStatus::cases() as $status) {
            $response = HealthCheckResponse::fromArray(['status' => $status->value]);
            self::assertSame($status, $response->status);
        }
    }

    #[Test]
    public function roundTripThroughArrayPreservesStatus(): void
    {
        $original = new HealthCheckResponse(HealthStatus::ServiceUnknown);
        $restored = HealthCheckResponse::fromArray($original->toArray());

        self::assertSame($original->status, $restored->status);
    }
}
