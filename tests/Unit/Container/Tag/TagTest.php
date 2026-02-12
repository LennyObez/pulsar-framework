<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Container\Tag;

use Attribute;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\Tag\Tag;
use ReflectionClass;

#[CoversClass(Tag::class)]
final class TagTest extends TestCase
{
    #[Test]
    public function constructsWithDefaults(): void
    {
        $tag = new Tag('event.listener');

        self::assertSame('event.listener', $tag->name);
        self::assertSame(0, $tag->priority);
        self::assertSame([], $tag->attributes);
    }

    #[Test]
    public function constructsWithAllParameters(): void
    {
        $tag = new Tag('cache.pool', 10, ['driver' => 'redis']);

        self::assertSame('cache.pool', $tag->name);
        self::assertSame(10, $tag->priority);
        self::assertSame(['driver' => 'redis'], $tag->attributes);
    }

    #[Test]
    public function isRepeatableAttribute(): void
    {
        $ref = new ReflectionClass(Tag::class);
        $attrs = $ref->getAttributes(Attribute::class);

        self::assertNotEmpty($attrs);
    }
}
