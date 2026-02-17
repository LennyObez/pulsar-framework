<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Container\Decorator;

use Attribute;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\Decorator\Decorate;
use ReflectionClass;

#[CoversClass(Decorate::class)]
final class DecorateTest extends TestCase
{
    #[Test]
    public function constructionWithRequiredParameters(): void
    {
        $attr = new Decorate(decorates: 'App\\LoggerInterface');

        self::assertSame('App\\LoggerInterface', $attr->decorates);
        self::assertSame(0, $attr->priority);
    }

    #[Test]
    public function constructionWithPriority(): void
    {
        $attr = new Decorate(decorates: 'App\\CacheInterface', priority: 50);

        self::assertSame('App\\CacheInterface', $attr->decorates);
        self::assertSame(50, $attr->priority);
    }

    #[Test]
    public function isClassAttribute(): void
    {
        $reflection = new ReflectionClass(Decorate::class);
        $attributes = $reflection->getAttributes(Attribute::class);

        self::assertNotEmpty($attributes);

        /** @var Attribute $attribute */
        $attribute = $attributes[0]->newInstance();
        self::assertTrue(($attribute->flags & Attribute::TARGET_CLASS) !== 0);
    }

    #[Test]
    public function negativePriorityAllowed(): void
    {
        $attr = new Decorate(decorates: 'Foo', priority: -10);

        self::assertSame(-10, $attr->priority);
    }
}
