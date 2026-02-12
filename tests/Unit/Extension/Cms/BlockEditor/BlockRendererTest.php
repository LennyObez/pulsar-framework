<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\BlockEditor;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\BlockRenderer;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeInterface;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeRegistry;
use Pulsar\Extension\Cms\Content\ContentBlock;

#[CoversClass(BlockRenderer::class)]
final class BlockRendererTest extends TestCase
{
    private BlockTypeRegistry $registry;
    private BlockRenderer $renderer;

    protected function setUp(): void
    {
        $this->registry = new BlockTypeRegistry();
        $this->renderer = new BlockRenderer($this->registry);
    }

    #[Test]
    public function rendersMixedBlocksToHtml(): void
    {
        $paragraphBlock = $this->createStub(BlockTypeInterface::class);
        $paragraphBlock->method('type')->willReturn('paragraph');
        $paragraphBlock->method('validate')->willReturn([]);
        $paragraphBlock->method('render')->willReturn('<p>Hello</p>');

        $headingBlock = $this->createStub(BlockTypeInterface::class);
        $headingBlock->method('type')->willReturn('heading');
        $headingBlock->method('validate')->willReturn([]);
        $headingBlock->method('render')->willReturn('<h1>Title</h1>');

        $this->registry->register($paragraphBlock);
        $this->registry->register($headingBlock);

        $blocks = [
            $this->makeContentBlock('heading', 0, ['text' => 'Title', 'level' => 1]),
            $this->makeContentBlock('paragraph', 1, ['text' => 'Hello']),
        ];

        $html = $this->renderer->render($blocks);

        self::assertStringContainsString('<h1>Title</h1>', $html);
        self::assertStringContainsString('<p>Hello</p>', $html);
    }

    #[Test]
    public function unknownBlockTypeRendersAsHtmlComment(): void
    {
        $blocks = [
            $this->makeContentBlock('custom-widget', 0, ['foo' => 'bar']),
        ];

        $html = $this->renderer->render($blocks);

        self::assertStringContainsString('<!-- unknown block type: custom-widget -->', $html);
    }

    #[Test]
    public function unknownBlockTypeWithSpecialCharsIsEscaped(): void
    {
        $blocks = [
            $this->makeContentBlock('<script>alert(1)</script>', 0, []),
        ];

        $html = $this->renderer->render($blocks);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    public function validationErrorsRenderedAsHtmlComment(): void
    {
        $block = $this->createStub(BlockTypeInterface::class);
        $block->method('type')->willReturn('paragraph');
        $block->method('validate')->willReturn(['text is required and must be a string']);

        $this->registry->register($block);

        $blocks = [
            $this->makeContentBlock('paragraph', 0, []),
        ];

        $html = $this->renderer->render($blocks);

        self::assertStringContainsString('<!-- block validation error (paragraph):', $html);
        self::assertStringContainsString('text is required and must be a string', $html);
    }

    #[Test]
    public function emptyBlockListReturnsEmptyString(): void
    {
        self::assertSame('', $this->renderer->render([]));
    }

    /** @param array<string, mixed> $data */
    private function makeContentBlock(string $type, int $sortOrder, array $data): ContentBlock
    {
        $now = new DateTimeImmutable();

        return new ContentBlock(
            id: 'block-' . $sortOrder,
            contentId: 'content-1',
            locale: 'en',
            blockType: $type,
            sortOrder: $sortOrder,
            data: $data,
            createdAt: $now,
            updatedAt: $now,
        );
    }
}
