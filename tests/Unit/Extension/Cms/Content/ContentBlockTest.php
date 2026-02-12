<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Content;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Content\ContentBlock;

#[CoversClass(ContentBlock::class)]
final class ContentBlockTest extends TestCase
{
    #[Test]
    public function constructorAssignsAllFields(): void
    {
        $now = new DateTimeImmutable('2025-01-01');
        $block = new ContentBlock(
            id: 'block-1',
            contentId: 'content-1',
            locale: 'en',
            blockType: 'text',
            sortOrder: 0,
            data: ['content' => 'Hello'],
            createdAt: $now,
            updatedAt: $now,
        );

        self::assertSame('block-1', $block->id);
        self::assertSame('content-1', $block->contentId);
        self::assertSame('en', $block->locale);
        self::assertSame('text', $block->blockType);
        self::assertSame(0, $block->sortOrder);
        self::assertSame(['content' => 'Hello'], $block->data);
        self::assertSame($now, $block->createdAt);
        self::assertSame($now, $block->updatedAt);
    }

    /**
     * @return iterable<string, array{string, string, array<string, mixed>}>
     */
    public static function factoryMethodProvider(): iterable
    {
        yield 'text' => ['text', 'text', ['content' => 'Hello world']];
        yield 'image' => ['image', 'image', ['media_id' => 'm1', 'alt' => 'Photo']];
        yield 'gallery' => ['gallery', 'gallery', ['media_ids' => ['m1', 'm2']]];
        yield 'code' => ['code', 'code', ['code' => 'echo 1;', 'language' => 'php']];
        yield 'embed' => ['embed', 'embed', ['url' => 'https://example.com']];
        yield 'html' => ['html', 'html', ['html' => '<div>Custom</div>']];
        yield 'cta' => ['cta', 'cta', ['label' => 'Buy', 'url' => '/buy']];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[Test]
    #[DataProvider('factoryMethodProvider')]
    public function factoryMethodCreatesCorrectBlockType(string $method, string $expectedType, array $data): void
    {
        /** @var ContentBlock $block */
        $block = ContentBlock::$method('b-1', 'c-1', 'en', 3, $data);

        self::assertSame($expectedType, $block->blockType);
        self::assertSame('b-1', $block->id);
        self::assertSame('c-1', $block->contentId);
        self::assertSame('en', $block->locale);
        self::assertSame(3, $block->sortOrder);
        self::assertSame($data, $block->data);
    }

    #[Test]
    public function factoryMethodSetsTimestamps(): void
    {
        $before = new DateTimeImmutable();
        $block = ContentBlock::text('b-1', 'c-1', 'en', 0, ['content' => 'x']);
        $after = new DateTimeImmutable();

        self::assertGreaterThanOrEqual($before, $block->createdAt);
        self::assertLessThanOrEqual($after, $block->createdAt);
        self::assertSame($block->createdAt, $block->updatedAt);
    }
}
