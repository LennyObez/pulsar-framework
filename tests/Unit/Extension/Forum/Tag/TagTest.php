<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Tag;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Tag\Tag;

#[CoversClass(Tag::class)]
final class TagTest extends TestCase
{
    #[Test]
    public function createReturnsTagWithZeroUsage(): void
    {
        $tag = Tag::create(
            id: 'tag-1',
            slug: 'php',
            name: 'PHP',
        );

        self::assertSame('tag-1', $tag->id);
        self::assertSame('php', $tag->slug);
        self::assertSame('PHP', $tag->name);
        self::assertSame('', $tag->description);
        self::assertSame(0, $tag->usageCount);
    }

    #[Test]
    public function createWithDescription(): void
    {
        $tag = Tag::create(
            id: 'tag-1',
            slug: 'php',
            name: 'PHP',
            description: 'PHP programming language',
        );

        self::assertSame('PHP programming language', $tag->description);
    }

    #[Test]
    public function rename(): void
    {
        $tag = Tag::create('tag-1', 'old-slug', 'Old Name');
        $renamed = $tag->rename('New Name', 'new-slug');

        self::assertSame('New Name', $renamed->name);
        self::assertSame('new-slug', $renamed->slug);
        self::assertSame('Old Name', $tag->name);
    }

    #[Test]
    public function describe(): void
    {
        $tag = Tag::create('tag-1', 'php', 'PHP');
        $described = $tag->describe('A scripting language');

        self::assertSame('A scripting language', $described->description);
        self::assertSame('', $tag->description);
    }

    #[Test]
    public function incrementUsage(): void
    {
        $tag = Tag::create('tag-1', 'php', 'PHP');
        $incremented = $tag->incrementUsage();

        self::assertSame(1, $incremented->usageCount);
        self::assertSame(0, $tag->usageCount);
    }

    #[Test]
    public function decrementUsageFloorsAtZero(): void
    {
        $tag = Tag::create('tag-1', 'php', 'PHP');
        $decremented = $tag->decrementUsage();

        self::assertSame(0, $decremented->usageCount);
    }

    #[Test]
    public function decrementUsageFromPositive(): void
    {
        $tag = Tag::create('tag-1', 'php', 'PHP');
        $tag = $tag->incrementUsage()->incrementUsage();
        $decremented = $tag->decrementUsage();

        self::assertSame(1, $decremented->usageCount);
    }
}
