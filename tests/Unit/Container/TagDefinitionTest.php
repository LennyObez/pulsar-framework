<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Container;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\TagDefinition;

#[CoversClass(TagDefinition::class)]
final class TagDefinitionTest extends TestCase
{
    #[Test]
    public function constructionWithAllParameters(): void
    {
        $tag = new TagDefinition(
            name: 'event.listener',
            priority: 100,
            attributes: ['event' => 'user.created'],
        );

        self::assertSame('event.listener', $tag->name);
        self::assertSame(100, $tag->priority);
        self::assertSame(['event' => 'user.created'], $tag->attributes);
    }

    #[Test]
    public function defaultPriorityIsZero(): void
    {
        $tag = new TagDefinition(name: 'cache.warmer');

        self::assertSame(0, $tag->priority);
    }

    #[Test]
    public function defaultAttributesAreEmpty(): void
    {
        $tag = new TagDefinition(name: 'cache.warmer');

        self::assertSame([], $tag->attributes);
    }

    #[Test]
    public function negativePriorityIsAllowed(): void
    {
        $tag = new TagDefinition(name: 'low.priority', priority: -50);

        self::assertSame(-50, $tag->priority);
    }

    #[Test]
    public function attributesWithMixedTypes(): void
    {
        $attrs = ['count' => 5, 'enabled' => true, 'tags' => ['a', 'b']];
        $tag = new TagDefinition(name: 'complex', attributes: $attrs);

        self::assertSame($attrs, $tag->attributes);
    }
}
