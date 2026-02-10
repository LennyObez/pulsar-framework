<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Tests\Unit\Health;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Handler\MethodDescriptor;
use Pulsar\Extension\Grpc\Handler\MethodType;
use Pulsar\Extension\Grpc\Health\HealthCheckResponse;
use Pulsar\Extension\Grpc\Health\HealthService;
use Pulsar\Extension\Grpc\Health\HealthStatus;

#[CoversClass(HealthService::class)]
final class HealthServiceTest extends TestCase
{
    private HealthService $service;

    protected function setUp(): void
    {
        $this->service = new HealthService();
    }

    #[Test]
    public function serviceNameReturnsHealthV1(): void
    {
        self::assertSame('grpc.health.v1.Health', $this->service->serviceName());
    }

    #[Test]
    public function methodsReturnsCheckDescriptor(): void
    {
        $methods = $this->service->methods();

        self::assertArrayHasKey('Check', $methods);
        self::assertInstanceOf(MethodDescriptor::class, $methods['Check']);
        self::assertSame('Check', $methods['Check']->name);
        self::assertSame('/grpc.health.v1.Health/Check', $methods['Check']->fullName);
        self::assertSame(MethodType::Unary, $methods['Check']->type);
    }

    #[Test]
    public function defaultOverallStatusIsServing(): void
    {
        $response = $this->service->check();

        self::assertSame(HealthStatus::Serving, $response->status);
    }

    #[Test]
    public function checkUnknownServiceReturnsServiceUnknown(): void
    {
        $response = $this->service->check('unknown.Service');

        self::assertSame(HealthStatus::ServiceUnknown, $response->status);
    }

    #[Test]
    public function setStatusUpdatesServiceHealth(): void
    {
        $this->service->setStatus('my.Service', HealthStatus::Serving);

        self::assertSame(HealthStatus::Serving, $this->service->check('my.Service')->status);

        $this->service->setStatus('my.Service', HealthStatus::NotServing);

        self::assertSame(HealthStatus::NotServing, $this->service->check('my.Service')->status);
    }

    #[Test]
    public function setOverallStatusChangesEmptyServiceKey(): void
    {
        $this->service->setOverallStatus(HealthStatus::NotServing);

        self::assertSame(HealthStatus::NotServing, $this->service->check()->status);
    }

    #[Test]
    public function setOverallStatusDoesNotAffectNamedServices(): void
    {
        $this->service->setStatus('my.Service', HealthStatus::Serving);
        $this->service->setOverallStatus(HealthStatus::NotServing);

        self::assertSame(HealthStatus::Serving, $this->service->check('my.Service')->status);
    }

    #[Test]
    public function invokeCheckReturnsJsonResponse(): void
    {
        $payload = json_encode(['service' => ''], JSON_THROW_ON_ERROR);
        $result = $this->service->invoke('Check', $payload);

        /** @var array{status: int} $decoded */
        $decoded = json_decode($result, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(HealthStatus::Serving->value, $decoded['status']);
    }

    #[Test]
    public function invokeCheckWithNamedService(): void
    {
        $this->service->setStatus('test.Svc', HealthStatus::NotServing);

        $payload = json_encode(['service' => 'test.Svc'], JSON_THROW_ON_ERROR);
        $result = $this->service->invoke('Check', $payload);

        /** @var array{status: int} $decoded */
        $decoded = json_decode($result, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(HealthStatus::NotServing->value, $decoded['status']);
    }

    #[Test]
    public function invokeUnknownMethodThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown method: Watch');

        $this->service->invoke('Watch', '{}');
    }

    #[Test]
    public function checkReturnsHealthCheckResponseInstance(): void
    {
        $response = $this->service->check();

        self::assertInstanceOf(HealthCheckResponse::class, $response);
    }

    #[Test]
    public function multipleServicesTrackedIndependently(): void
    {
        $this->service->setStatus('svc.A', HealthStatus::Serving);
        $this->service->setStatus('svc.B', HealthStatus::NotServing);
        $this->service->setStatus('svc.C', HealthStatus::Unknown);

        self::assertSame(HealthStatus::Serving, $this->service->check('svc.A')->status);
        self::assertSame(HealthStatus::NotServing, $this->service->check('svc.B')->status);
        self::assertSame(HealthStatus::Unknown, $this->service->check('svc.C')->status);
    }
}
