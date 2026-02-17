<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\MedicalDevices;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\MedicalDevices\MedicalDevicesExtension;
use Pulsar\Extension\MedicalDevices\MedicalDevicesServiceProvider;

#[CoversClass(MedicalDevicesExtension::class)]
final class MedicalDevicesExtensionTest extends TestCase
{
    private MedicalDevicesExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new MedicalDevicesExtension();
    }

    #[Test]
    public function nameReturnsPulsarMedicalDevices(): void
    {
        self::assertSame('pulsar/medical-devices', $this->extension->name());
    }

    #[Test]
    public function providersIncludesServiceProvider(): void
    {
        $providers = $this->extension->providers();

        self::assertCount(1, $providers);
        self::assertSame(MedicalDevicesServiceProvider::class, $providers[0]);
    }
}
