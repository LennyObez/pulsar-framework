<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Content;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Content\ContentBlock;

final class ContentBlockTest extends TestCase
{
    #[Test]
    public function text_block_factory(): void
    {
        $block = ContentBlock::text('b1', 'c1', 'en', 0, ['content' => '<p>Hello</p>']);

        self::assertSame('b1', $block->id);
        self::assertSame('c1', $block->contentId);
        self::assertSame('en', $block->locale);
        self::assertSame('text', $block->blockType);
        self::assertSame(0, $block->sortOrder);
        self::assertSame(['content' => '<p>Hello</p>'], $block->data);
    }

    #[Test]
    public function image_block_factory(): void
    {
        $block = ContentBlock::image('b2', 'c1', 'en', 1, ['media_id' => 'm1', 'alt' => 'Photo']);

        self::assertSame('image', $block->blockType);
        self::assertSame(1, $block->sortOrder);
        self::assertSame('m1', $block->data['media_id']);
    }

    #[Test]
    public function gallery_block_factory(): void
    {
        $block = ContentBlock::gallery('b3', 'c1', 'en', 2, ['media_ids' => ['m1', 'm2']]);

        self::assertSame('gallery', $block->blockType);
        self::assertSame(['m1', 'm2'], $block->data['media_ids']);
    }

    #[Test]
    public function code_block_factory(): void
    {
        $block = ContentBlock::code('b4', 'c1', 'en', 3, ['code' => '<?php echo 1;', 'language' => 'php']);

        self::assertSame('code', $block->blockType);
        self::assertSame('php', $block->data['language']);
    }

    #[Test]
    public function embed_block_factory(): void
    {
        $block = ContentBlock::embed('b5', 'c1', 'en', 4, ['url' => 'https://youtube.com/watch?v=abc']);

        self::assertSame('embed', $block->blockType);
    }

    #[Test]
    public function html_block_factory(): void
    {
        $block = ContentBlock::html('b6', 'c1', 'en', 5, ['html' => '<div class="custom">HTML</div>']);

        self::assertSame('html', $block->blockType);
    }

    #[Test]
    public function cta_block_factory(): void
    {
        $block = ContentBlock::cta('b7', 'c1', 'en', 6, ['label' => 'Click', 'url' => '/signup']);

        self::assertSame('cta', $block->blockType);
        self::assertSame('Click', $block->data['label']);
    }

    #[Test]
    public function timestamps_are_set_on_creation(): void
    {
        $block = ContentBlock::text('b1', 'c1', 'en', 0, ['content' => 'test']);

        self::assertEqualsWithDelta(time(), $block->createdAt->getTimestamp(), 2);
        self::assertEqualsWithDelta(time(), $block->updatedAt->getTimestamp(), 2);
    }

    #[Test]
    public function sort_order_determines_position(): void
    {
        $blocks = [
            ContentBlock::text('b3', 'c1', 'en', 2, ['content' => 'third']),
            ContentBlock::text('b1', 'c1', 'en', 0, ['content' => 'first']),
            ContentBlock::text('b2', 'c1', 'en', 1, ['content' => 'second']),
        ];

        usort($blocks, static fn(ContentBlock $a, ContentBlock $b) => $a->sortOrder <=> $b->sortOrder);

        self::assertSame('b1', $blocks[0]->id);
        self::assertSame('b2', $blocks[1]->id);
        self::assertSame('b3', $blocks[2]->id);
    }
}
