<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Attribute;

use Attribute;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Attribute\TenantShared;
use ReflectionClass;

final class TenantSharedTest extends TestCase
{
    #[Test]
    public function isInstantiable(): void
    {
        $attr = new TenantShared();

        self::assertInstanceOf(TenantShared::class, $attr);
    }

    #[Test]
    public function targetsClasses(): void
    {
        $reflection = new ReflectionClass(TenantShared::class);
        $attributes = $reflection->getAttributes(Attribute::class);

        self::assertNotEmpty($attributes);
        $attrInstance = $attributes[0]->newInstance();
        self::assertSame(Attribute::TARGET_CLASS, $attrInstance->flags);
    }
}
