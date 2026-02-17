<?php

declare(strict_types=1);

namespace Pulsar\Extension\Eidas\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\Eidas\Config\EidasConfig;
use Pulsar\Extension\Eidas\Contracts\DigitalSignatureServiceInterface;
use Pulsar\Extension\Eidas\Contracts\ElectronicSealServiceInterface;
use Pulsar\Extension\Eidas\Contracts\RegisteredDeliveryServiceInterface;
use Pulsar\Extension\Eidas\Contracts\TimestampServiceInterface;
use Pulsar\Extension\Eidas\EidasServiceProvider;

#[CoversClass(EidasServiceProvider::class)]
final class EidasServiceProviderTest extends TestCase
{
    #[Test]
    public function providesListsAllServices(): void
    {
        $provider = new EidasServiceProvider();
        $provides = $provider->provides();

        self::assertContains(EidasConfig::class, $provides);
        self::assertContains(DigitalSignatureServiceInterface::class, $provides);
        self::assertContains(ElectronicSealServiceInterface::class, $provides);
        self::assertContains(TimestampServiceInterface::class, $provides);
        self::assertContains(RegisteredDeliveryServiceInterface::class, $provides);
    }

    #[Test]
    public function registerBindsAllServices(): void
    {
        $bindings = [];
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::exactly(5))
            ->method('bind')
            ->willReturnCallback(function (string $id) use (&$bindings): void {
                $bindings[] = $id;
            });

        $provider = new EidasServiceProvider();
        $provider->register($container);

        self::assertContains(EidasConfig::class, $bindings);
        self::assertContains(DigitalSignatureServiceInterface::class, $bindings);
        self::assertContains(ElectronicSealServiceInterface::class, $bindings);
        self::assertContains(TimestampServiceInterface::class, $bindings);
        self::assertContains(RegisteredDeliveryServiceInterface::class, $bindings);
    }
}
