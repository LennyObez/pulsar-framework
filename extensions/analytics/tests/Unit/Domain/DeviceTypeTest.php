<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Domain\DeviceType;

final class DeviceTypeTest extends TestCase
{
    #[Test]
    public function allCasesHaveStringValues(): void
    {
        self::assertSame('desktop', DeviceType::Desktop->value);
        self::assertSame('mobile', DeviceType::Mobile->value);
        self::assertSame('tablet', DeviceType::Tablet->value);
        self::assertSame('unknown', DeviceType::Unknown->value);
    }

    #[Test]
    public function canBeCreatedFromString(): void
    {
        self::assertSame(DeviceType::Desktop, DeviceType::from('desktop'));
        self::assertSame(DeviceType::Mobile, DeviceType::from('mobile'));
        self::assertSame(DeviceType::Tablet, DeviceType::from('tablet'));
        self::assertSame(DeviceType::Unknown, DeviceType::from('unknown'));
    }

    #[Test]
    public function tryFromReturnsNullForInvalidValue(): void
    {
        $invalidValue = 'laptop';
        self::assertNull(DeviceType::tryFrom($invalidValue));
    }
}
