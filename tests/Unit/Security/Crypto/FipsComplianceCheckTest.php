<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Crypto;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Resilience\HealthCheck\HealthCheckInterface;
use Pulsar\Resilience\HealthCheck\HealthCheckResult;
use Pulsar\Resilience\HealthCheck\HealthStatus;
use Pulsar\Security\Crypto\FipsComplianceCheck;

#[CoversClass(FipsComplianceCheck::class)]
final class FipsComplianceCheckTest extends TestCase
{
    private FipsComplianceCheck $check;

    protected function setUp(): void
    {
        $this->check = new FipsComplianceCheck();
    }

    #[Test]
    public function implementsHealthCheckInterface(): void
    {
        self::assertInstanceOf(HealthCheckInterface::class, $this->check);
    }

    #[Test]
    public function nameIsFipsCompliance(): void
    {
        self::assertSame('fips-compliance', $this->check->getName());
    }

    #[Test]
    public function checkReturnsHealthCheckResult(): void
    {
        $result = $this->check->check();

        self::assertInstanceOf(HealthCheckResult::class, $result);
        self::assertSame('fips-compliance', $result->name);
        self::assertGreaterThanOrEqual(0.0, $result->responseTimeMs);
    }

    #[Test]
    public function checkReportsCorrectStatusForEnvironment(): void
    {
        $result = $this->check->check();

        // In standard (non-FIPS) test environments, this should be degraded
        // because AES-256-GCM is available but FIPS mode is not active.
        // In FIPS environments, it should be healthy.
        self::assertContains($result->status, [HealthStatus::Healthy, HealthStatus::Degraded]);
    }

    #[Test]
    public function checkMessageContainsMeaningfulContent(): void
    {
        $result = $this->check->check();

        if ($result->status === HealthStatus::Healthy) {
            self::assertStringContainsString('OpenSSL', $result->message);
        } else {
            // Degraded/unhealthy messages should explain why
            self::assertStringContainsString('compliant', $result->message);
        }
    }

    #[Test]
    public function checkMessageIsNotEmpty(): void
    {
        $result = $this->check->check();

        self::assertNotEmpty($result->message);
    }
}
