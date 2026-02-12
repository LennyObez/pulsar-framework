<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Tag;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Tag\Tag;

final class TagTest extends TestCase
{
    #[Test]
    public function createSetsDefaults(): void
    {
        $tag = Tag::create(id: 'tag-1', slug: 'php', name: 'PHP');

        self::assertSame('tag-1', $tag->id);
        self::assertSame('php', $tag->slug);
        self::assertSame('PHP', $tag->name);
        self::assertSame('', $tag->description);
        self::assertSame(0, $tag->usageCount);
    }

    #[Test]
    public function createWithDescription(): void
    {
        $tag = Tag::create(id: 'tag-1', slug: 'php', name: 'PHP', description: 'PHP language');

        self::assertSame('PHP language', $tag->description);
    }

    #[Test]
    public function renameChangesNameAndSlug(): void
    {
        $tag = Tag::create(id: 'tag-1', slug: 'php', name: 'PHP');
        $renamed = $tag->rename('PHP 8', 'php-8');

        self::assertSame('PHP 8', $renamed->name);
        self::assertSame('php-8', $renamed->slug);
        self::assertSame('PHP', $tag->name);
    }

    #[Test]
    public function describeUpdatesDescription(): void
    {
        $tag = Tag::create(id: 'tag-1', slug: 'php', name: 'PHP');
        $described = $tag->describe('About PHP');

        self::assertSame('About PHP', $described->description);
        self::assertSame('', $tag->description);
    }

    #[Test]
    public function incrementUsageAddsOne(): void
    {
        $tag = Tag::create(id: 'tag-1', slug: 'php', name: 'PHP');

        $incremented = $tag->incrementUsage();
        self::assertSame(1, $incremented->usageCount);

        $twice = $incremented->incrementUsage();
        self::assertSame(2, $twice->usageCount);
    }

    #[Test]
    public function decrementUsageSubtractsOne(): void
    {
        $tag = new Tag(id: 'tag-1', slug: 'php', name: 'PHP', description: '', usageCount: 3);
        $decremented = $tag->decrementUsage();

        self::assertSame(2, $decremented->usageCount);
    }

    #[Test]
    public function decrementUsageFloorsAtZero(): void
    {
        $tag = Tag::create(id: 'tag-1', slug: 'php', name: 'PHP');
        $decremented = $tag->decrementUsage();

        self::assertSame(0, $decremented->usageCount);
    }
}
