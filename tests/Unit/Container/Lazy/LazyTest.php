<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Container\Lazy;

use Attribute;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\Lazy\Lazy;
use ReflectionClass;

#[CoversClass(Lazy::class)]
final class LazyTest extends TestCase
{
    #[Test]
    public function canBeInstantiated(): void
    {
        $lazy = new Lazy();

        self::assertInstanceOf(Lazy::class, $lazy);
    }

    #[Test]
    public function isClassAttribute(): void
    {
        $reflection = new ReflectionClass(Lazy::class);
        $attributes = $reflection->getAttributes(Attribute::class);

        self::assertNotEmpty($attributes);

        /** @var Attribute $attribute */
        $attribute = $attributes[0]->newInstance();
        self::assertTrue(($attribute->flags & Attribute::TARGET_CLASS) !== 0);
    }

    #[Test]
    public function isNotRepeatable(): void
    {
        $reflection = new ReflectionClass(Lazy::class);
        $attributes = $reflection->getAttributes(Attribute::class);

        /** @var Attribute $attribute */
        $attribute = $attributes[0]->newInstance();
        self::assertFalse(($attribute->flags & Attribute::IS_REPEATABLE) !== 0);
    }
}
