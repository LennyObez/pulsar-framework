<?php

declare(strict_types=1);

namespace Pulsar\Extension\MedicalDevices\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\MedicalDevices\Internal\InMemoryUdiRegistry;
use Pulsar\Extension\MedicalDevices\MedicalDevicesServiceProvider;
use Pulsar\Extension\MedicalDevices\Udi\UdiRegistryInterface;
use Pulsar\Extension\MedicalDevices\Udi\UdiValidator;

#[CoversClass(MedicalDevicesServiceProvider::class)]
final class MedicalDevicesServiceProviderTest extends TestCase
{
    #[Test]
    public function registerBindsUdiValidatorAndRegistry(): void
    {
        $registered = [];
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::exactly(2))
            ->method('instance')
            ->willReturnCallback(function (string $id, object $instance) use (&$registered): void {
                $registered[$id] = $instance;
            });

        $provider = new MedicalDevicesServiceProvider();
        $provider->register($container);

        self::assertArrayHasKey(UdiValidator::class, $registered);
        self::assertInstanceOf(UdiValidator::class, $registered[UdiValidator::class]);
        self::assertArrayHasKey(UdiRegistryInterface::class, $registered);
        self::assertInstanceOf(InMemoryUdiRegistry::class, $registered[UdiRegistryInterface::class]);
    }

    #[Test]
    public function providesListsBothServices(): void
    {
        $provider = new MedicalDevicesServiceProvider();
        $provides = $provider->provides();

        self::assertContains(UdiValidator::class, $provides);
        self::assertContains(UdiRegistryInterface::class, $provides);
    }
}
