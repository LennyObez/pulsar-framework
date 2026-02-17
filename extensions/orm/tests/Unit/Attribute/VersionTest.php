<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Attribute;

use Attribute;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Attribute\Version;
use ReflectionClass;

final class VersionTest extends TestCase
{
    #[Test]
    public function isInstantiable(): void
    {
        $attr = new Version();

        self::assertInstanceOf(Version::class, $attr);
    }

    #[Test]
    public function targetsProperties(): void
    {
        $reflection = new ReflectionClass(Version::class);
        $attributes = $reflection->getAttributes(Attribute::class);

        self::assertNotEmpty($attributes);
        $attrInstance = $attributes[0]->newInstance();
        self::assertSame(Attribute::TARGET_PROPERTY, $attrInstance->flags);
    }
}
