<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Container\Tag;

use Attribute;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\Tag\TaggedIterator;
use ReflectionClass;

#[CoversClass(TaggedIterator::class)]
final class TaggedIteratorTest extends TestCase
{
    #[Test]
    public function constructionStoresTagName(): void
    {
        $iterator = new TaggedIterator('event.listener');

        self::assertSame('event.listener', $iterator->tag);
    }

    #[Test]
    public function isParameterAttribute(): void
    {
        $reflection = new ReflectionClass(TaggedIterator::class);
        $attributes = $reflection->getAttributes(Attribute::class);

        self::assertNotEmpty($attributes);

        /** @var Attribute $attribute */
        $attribute = $attributes[0]->newInstance();
        self::assertTrue(($attribute->flags & Attribute::TARGET_PARAMETER) !== 0);
    }

    #[Test]
    public function tagNamePreservedExactly(): void
    {
        $iterator = new TaggedIterator('console.command');

        self::assertSame('console.command', $iterator->tag);
    }

    #[Test]
    public function differentTagNamesCreateDistinctInstances(): void
    {
        $a = new TaggedIterator('tag.a');
        $b = new TaggedIterator('tag.b');

        self::assertNotSame($a->tag, $b->tag);
    }
}
