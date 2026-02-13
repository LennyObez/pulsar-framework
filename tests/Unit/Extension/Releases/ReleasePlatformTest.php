<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Releases;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Releases\ReleasePlatform;
use ValueError;

final class ReleasePlatformTest extends TestCase
{
    #[Test]
    public function caseCount(): void
    {
        self::assertCount(3, ReleasePlatform::cases());
    }

    #[Test]
    public function values(): void
    {
        self::assertSame('android', ReleasePlatform::Android->value);
        self::assertSame('ios', ReleasePlatform::Ios->value);
        self::assertSame('web', ReleasePlatform::Web->value);
    }

    #[Test]
    public function fromValidValue(): void
    {
        self::assertSame(ReleasePlatform::Android, ReleasePlatform::from('android'));
        self::assertSame(ReleasePlatform::Ios, ReleasePlatform::from('ios'));
        self::assertSame(ReleasePlatform::Web, ReleasePlatform::from('web'));
    }

    #[Test]
    public function tryFromInvalidReturnsNull(): void
    {
        self::assertNull(ReleasePlatform::tryFrom('macos'));
    }

    #[Test]
    public function fromInvalidValueThrows(): void
    {
        $this->expectException(ValueError::class);
        ReleasePlatform::from('macos');
    }
}
