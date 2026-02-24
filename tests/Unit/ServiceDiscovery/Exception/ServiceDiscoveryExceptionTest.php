<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\ServiceDiscovery\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\ServiceDiscovery\Exception\ServiceDiscoveryException;

#[CoversClass(ServiceDiscoveryException::class)]
final class ServiceDiscoveryExceptionTest extends TestCase
{
    #[Test]
    public function serviceNotFound(): void
    {
        $e = ServiceDiscoveryException::serviceNotFound('payment-service');

        self::assertStringContainsString('payment-service', $e->getMessage());
        self::assertStringContainsString('not registered', $e->getMessage());
    }

    #[Test]
    public function noHealthyInstances(): void
    {
        $e = ServiceDiscoveryException::noHealthyInstances('auth-service');

        self::assertStringContainsString('auth-service', $e->getMessage());
        self::assertStringContainsString('healthy', $e->getMessage());
    }
}
