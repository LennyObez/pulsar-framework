<?php

declare(strict_types=1);

namespace Pulsar\Extension\Devices\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Devices\DevicesServiceProvider;

final class DevicesServiceProviderTest extends TestCase
{
    #[Test]
    public function providesReturnsFourBindings(): void
    {
        $provider = new DevicesServiceProvider();
        $provides = $provider->provides();

        self::assertCount(4, $provides);
        self::assertContains('Pulsar\Extension\Devices\UserDeviceRepositoryInterface', $provides);
        self::assertContains('Pulsar\Extension\Devices\Internal\DeviceService', $provides);
    }
}
