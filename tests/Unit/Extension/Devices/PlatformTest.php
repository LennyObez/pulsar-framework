<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Devices;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Devices\Platform;
use ValueError;

final class PlatformTest extends TestCase
{
    #[Test]
    public function caseCount(): void
    {
        self::assertCount(3, Platform::cases());
    }

    #[Test]
    public function values(): void
    {
        self::assertSame('android', Platform::Android->value);
        self::assertSame('ios', Platform::iOS->value);
        self::assertSame('web', Platform::Web->value);
    }

    #[Test]
    public function fromValidValue(): void
    {
        self::assertSame(Platform::iOS, Platform::from('ios'));
        self::assertSame(Platform::Android, Platform::from('android'));
        self::assertSame(Platform::Web, Platform::from('web'));
    }

    #[Test]
    public function tryFromInvalidReturnsNull(): void
    {
        self::assertNull(Platform::tryFrom('linux'));
    }

    #[Test]
    public function fromInvalidValueThrows(): void
    {
        $this->expectException(ValueError::class);
        Platform::from('linux');
    }
}
