<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\BlockEditor\CoreBlocks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\LatestPostsBlock;

#[CoversClass(LatestPostsBlock::class)]
final class LatestPostsBlockTest extends TestCase
{
    private LatestPostsBlock $block;

    protected function setUp(): void
    {
        $this->block = new LatestPostsBlock();
    }

    public function testType(): void
    {
        self::assertSame('latest-posts', $this->block->type());
    }

    public function testRenderWithPosts(): void
    {
        $html = $this->block->render([
            'posts' => [
                [
                    'title' => 'Hello World',
                    'url' => '/hello',
                    'excerpt' => 'A first post',
                    'date' => '2026-01-01',
                ],
            ],
            'showExcerpt' => true,
            'showDate' => true,
        ]);

        self::assertStringContainsString('class="latest-posts"', $html);
        self::assertStringContainsString('Hello World</a>', $html);
        self::assertStringContainsString('href="/hello"', $html);
        self::assertStringContainsString('A first post', $html);
        self::assertStringContainsString('2026-01-01', $html);
    }

    public function testRenderWithThumbnail(): void
    {
        $html = $this->block->render([
            'posts' => [
                ['title' => 'Post', 'url' => '/p', 'thumbnail' => '/img/thumb.jpg'],
            ],
            'showThumbnail' => true,
        ]);

        self::assertStringContainsString('src="/img/thumb.jpg"', $html);
        self::assertStringContainsString('loading="lazy"', $html);
    }

    public function testRenderHidesExcerptWhenDisabled(): void
    {
        $html = $this->block->render([
            'posts' => [
                ['title' => 'Post', 'url' => '/p', 'excerpt' => 'Hidden excerpt'],
            ],
            'showExcerpt' => false,
        ]);

        self::assertStringNotContainsString('Hidden excerpt', $html);
    }

    public function testRenderShowsAuthorWhenEnabled(): void
    {
        $html = $this->block->render([
            'posts' => [
                ['title' => 'Post', 'url' => '/p', 'author' => 'Jane Doe'],
            ],
            'showAuthor' => true,
        ]);

        self::assertStringContainsString('Jane Doe', $html);
    }

    public function testRenderEscapesXss(): void
    {
        $html = $this->block->render([
            'posts' => [
                ['title' => '<script>xss</script>', 'url' => '"><img src=x>'],
            ],
        ]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<img', $html);
    }

    public function testValidateRequiresPosts(): void
    {
        $errors = $this->block->validate([]);
        self::assertContains('posts is required and must be an array', $errors);
    }

    public function testValidateRejectsInvalidCount(): void
    {
        $errors = $this->block->validate([
            'posts' => [],
            'count' => 100,
        ]);

        self::assertNotEmpty($errors);
    }

    public function testValidateAcceptsValidData(): void
    {
        $errors = $this->block->validate([
            'posts' => [
                ['title' => 'Post', 'url' => '/p'],
            ],
        ]);

        self::assertSame([], $errors);
    }
}
