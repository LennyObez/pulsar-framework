<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Releases;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Releases\DeviceType;
use ValueError;

final class DeviceTypeTest extends TestCase
{
    #[Test]
    public function caseCount(): void
    {
        self::assertCount(3, DeviceType::cases());
    }

    #[Test]
    public function values(): void
    {
        self::assertSame('android', DeviceType::Android->value);
        self::assertSame('ios', DeviceType::Ios->value);
        self::assertSame('both', DeviceType::Both->value);
    }

    #[Test]
    public function fromValidValue(): void
    {
        self::assertSame(DeviceType::Android, DeviceType::from('android'));
        self::assertSame(DeviceType::Ios, DeviceType::from('ios'));
        self::assertSame(DeviceType::Both, DeviceType::from('both'));
    }

    #[Test]
    public function tryFromInvalidReturnsNull(): void
    {
        self::assertNull(DeviceType::tryFrom('tablet'));
    }

    #[Test]
    public function fromInvalidValueThrows(): void
    {
        $this->expectException(ValueError::class);
        DeviceType::from('tablet');
    }
}
