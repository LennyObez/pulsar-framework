<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Grpc\Health;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Health\HealthCheckResponse;
use Pulsar\Extension\Grpc\Health\HealthService;
use Pulsar\Extension\Grpc\Health\HealthStatus;

use function assert;
use function is_array;

#[CoversClass(HealthService::class)]
#[CoversClass(HealthCheckResponse::class)]
final class HealthServiceTest extends TestCase
{
    #[Test]
    public function serviceNameReturnsGrpcHealthV1(): void
    {
        $service = new HealthService();
        self::assertSame('grpc.health.v1.Health', $service->serviceName());
    }

    #[Test]
    public function methodsContainsCheckMethod(): void
    {
        $service = new HealthService();
        $methods = $service->methods();

        self::assertArrayHasKey('Check', $methods);
        self::assertSame('Check', $methods['Check']->name);
        self::assertSame('/grpc.health.v1.Health/Check', $methods['Check']->fullName);
    }

    #[Test]
    public function checkReturnsServingByDefault(): void
    {
        $service = new HealthService();
        $response = $service->check();

        self::assertSame(HealthStatus::Serving, $response->status);
    }

    #[Test]
    public function checkReturnsServiceUnknownForUnregisteredService(): void
    {
        $service = new HealthService();
        $response = $service->check('unregistered.service');

        self::assertSame(HealthStatus::ServiceUnknown, $response->status);
    }

    #[Test]
    public function setStatusUpdatesSpecificService(): void
    {
        $service = new HealthService();
        $service->setStatus('my.service', HealthStatus::NotServing);

        $response = $service->check('my.service');
        self::assertSame(HealthStatus::NotServing, $response->status);

        // Overall status unaffected
        self::assertSame(HealthStatus::Serving, $service->check()->status);
    }

    #[Test]
    public function setOverallStatusUpdatesEmptyServiceKey(): void
    {
        $service = new HealthService();
        $service->setOverallStatus(HealthStatus::NotServing);

        self::assertSame(HealthStatus::NotServing, $service->check()->status);
    }

    #[Test]
    public function invokeCheckMethodWithPayload(): void
    {
        $service = new HealthService();
        $service->setStatus('test.svc', HealthStatus::Serving);

        $result = $service->invoke('Check', '{"service":"test.svc"}');
        $decoded = json_decode($result, true);
        assert(is_array($decoded));

        self::assertSame(HealthStatus::Serving->value, $decoded['status']);
    }

    #[Test]
    public function invokeCheckMethodWithEmptyService(): void
    {
        $service = new HealthService();

        $result = $service->invoke('Check', '{}');
        $decoded = json_decode($result, true);
        assert(is_array($decoded));

        self::assertSame(HealthStatus::Serving->value, $decoded['status']);
    }

    #[Test]
    public function invokeThrowsForUnknownMethod(): void
    {
        $service = new HealthService();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Unknown method: Watch');
        $service->invoke('Watch', '{}');
    }

    #[Test]
    public function healthCheckResponseFromArrayWithValidStatus(): void
    {
        $response = HealthCheckResponse::fromArray(['status' => 2]);
        self::assertSame(HealthStatus::NotServing, $response->status);
    }

    #[Test]
    public function healthCheckResponseFromArrayWithMissingStatus(): void
    {
        $response = HealthCheckResponse::fromArray([]);
        self::assertSame(HealthStatus::Unknown, $response->status);
    }

    #[Test]
    public function healthCheckResponseFromArrayWithNonIntStatus(): void
    {
        $response = HealthCheckResponse::fromArray(['status' => 'invalid']);
        self::assertSame(HealthStatus::Unknown, $response->status);
    }

    #[Test]
    public function healthCheckResponseToArray(): void
    {
        $response = new HealthCheckResponse(HealthStatus::Serving);
        self::assertSame(['status' => 1], $response->toArray());
    }
}
