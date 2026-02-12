<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Domain\DeviceInfo;
use Pulsar\Extension\Analytics\Domain\DeviceType;

final class DeviceInfoTest extends TestCase
{
    #[Test]
    public function constructWithAllFields(): void
    {
        $info = new DeviceInfo(
            browser: 'Chrome',
            browserVersion: '120.0',
            os: 'Windows',
            osVersion: '10',
            deviceType: DeviceType::Desktop,
        );

        self::assertSame('Chrome', $info->browser);
        self::assertSame('120.0', $info->browserVersion);
        self::assertSame('Windows', $info->os);
        self::assertSame('10', $info->osVersion);
        self::assertSame(DeviceType::Desktop, $info->deviceType);
    }

    #[Test]
    public function unknownFactoryReturnsUnknownValues(): void
    {
        $info = DeviceInfo::unknown();

        self::assertSame('Unknown', $info->browser);
        self::assertSame('', $info->browserVersion);
        self::assertSame('Unknown', $info->os);
        self::assertSame('', $info->osVersion);
        self::assertSame(DeviceType::Unknown, $info->deviceType);
    }
}
