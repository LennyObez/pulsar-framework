<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\ServiceDiscovery;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\ServiceDiscovery\Exception\ServiceDiscoveryException;
use RuntimeException;

#[CoversClass(ServiceDiscoveryException::class)]
final class ServiceDiscoveryExceptionTest extends TestCase
{
    #[Test]
    public function serviceNotFoundProducesCorrectMessage(): void
    {
        $exception = ServiceDiscoveryException::serviceNotFound('billing');

        self::assertInstanceOf(ServiceDiscoveryException::class, $exception);
        self::assertStringContainsString('billing', $exception->getMessage());
        self::assertStringContainsString('not registered', $exception->getMessage());
    }

    #[Test]
    public function noHealthyInstancesProducesCorrectMessage(): void
    {
        $exception = ServiceDiscoveryException::noHealthyInstances('auth');

        self::assertInstanceOf(ServiceDiscoveryException::class, $exception);
        self::assertStringContainsString('auth', $exception->getMessage());
        self::assertStringContainsString('No healthy instances', $exception->getMessage());
    }

    #[Test]
    public function extendsRuntimeException(): void
    {
        $exception = ServiceDiscoveryException::serviceNotFound('test');

        self::assertInstanceOf(RuntimeException::class, $exception);
    }
}
