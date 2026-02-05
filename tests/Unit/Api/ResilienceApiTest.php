<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Api;
use Pulsar\Resilience\CircuitBreakerState;
use Pulsar\Resilience\Exception\ResilienceException;
use Pulsar\Resilience\HealthCheck\HealthCheckInterface;
use Pulsar\Resilience\HealthCheck\HealthCheckResult;
use Pulsar\Resilience\HealthCheck\HealthReport;
use Pulsar\Resilience\HealthCheck\HealthStatus;
use Pulsar\Resilience\Repair\RepairDiagnosis;
use Pulsar\Resilience\Repair\RepairJobInterface;
use Pulsar\Resilience\Repair\RepairResult;
use Pulsar\Resilience\RetryPolicy;
use Pulsar\Resilience\RetryResult;

#[CoversClass(Api::class)]
final class ResilienceApiTest extends TestCase
{
    use ApiAssertionsTrait;

    #[Test]
    public function healthCheckInterfaceIsPublicApi(): void
    {
        self::assertHasApiAttribute(HealthCheckInterface::class);
    }

    #[Test]
    public function repairJobInterfaceIsPublicApi(): void
    {
        self::assertHasApiAttribute(RepairJobInterface::class);
    }

    #[Test]
    public function circuitBreakerStateIsPublicApi(): void
    {
        self::assertHasApiAttribute(CircuitBreakerState::class);
        self::assertEnumCases(CircuitBreakerState::class, ['Closed', 'Open', 'HalfOpen']);
    }

    #[Test]
    public function healthStatusIsPublicApi(): void
    {
        self::assertHasApiAttribute(HealthStatus::class);
    }

    #[Test]
    public function retryPolicyIsPublicApi(): void
    {
        self::assertHasApiAttribute(RetryPolicy::class);
    }

    #[Test]
    public function retryResultIsPublicApi(): void
    {
        self::assertHasApiAttribute(RetryResult::class);
        self::assertClassIsReadonly(RetryResult::class);
    }

    #[Test]
    public function retryResultHasStaticFactories(): void
    {
        self::assertStaticFactoryExists(RetryResult::class, 'success');
        self::assertStaticFactoryExists(RetryResult::class, 'exhausted');
    }

    #[Test]
    public function healthCheckResultIsPublicApi(): void
    {
        self::assertHasApiAttribute(HealthCheckResult::class);
        self::assertClassIsReadonly(HealthCheckResult::class);
    }

    #[Test]
    public function healthReportIsPublicApi(): void
    {
        self::assertHasApiAttribute(HealthReport::class);
        self::assertClassIsReadonly(HealthReport::class);
    }

    #[Test]
    public function repairValueObjectsArePublicApi(): void
    {
        self::assertHasApiAttribute(RepairDiagnosis::class);
        self::assertHasApiAttribute(RepairResult::class);
        self::assertClassIsReadonly(RepairDiagnosis::class);
        self::assertClassIsReadonly(RepairResult::class);
    }

    #[Test]
    public function resilienceExceptionIsPublicApi(): void
    {
        self::assertHasApiAttribute(ResilienceException::class);
    }

    #[Test]
    public function resilienceExceptionHasStaticFactories(): void
    {
        self::assertStaticFactoryExists(ResilienceException::class, 'circuitOpen');
        self::assertStaticFactoryExists(ResilienceException::class, 'retryExhausted');
    }
}
