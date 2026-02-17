<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\BlockEditor\CoreBlocks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\ParagraphBlock;

#[CoversClass(ParagraphBlock::class)]
final class ParagraphBlockTest extends TestCase
{
    private ParagraphBlock $block;

    protected function setUp(): void
    {
        $this->block = new ParagraphBlock();
    }

    #[Test]
    public function typeReturnsParagraph(): void
    {
        self::assertSame('paragraph', $this->block->type());
    }

    #[Test]
    public function renderOutputsParagraphElement(): void
    {
        $html = $this->block->render(['text' => 'Hello world']);

        self::assertStringContainsString('<p', $html);
        self::assertStringContainsString('Hello world', $html);
        self::assertStringContainsString('</p>', $html);
    }

    #[Test]
    #[DataProvider('alignmentProvider')]
    public function renderAppliesValidAlignment(string $alignment): void
    {
        $html = $this->block->render([
            'text' => 'Aligned text',
            'alignment' => $alignment,
        ]);

        self::assertStringContainsString("text-align:$alignment", $html);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function alignmentProvider(): array
    {
        return [
            'left' => ['left'],
            'center' => ['center'],
            'right' => ['right'],
            'justify' => ['justify'],
        ];
    }

    #[Test]
    public function renderIgnoresInvalidAlignment(): void
    {
        $html = $this->block->render([
            'text' => 'Test',
            'alignment' => 'stretch',
        ]);

        self::assertStringNotContainsString('text-align:', $html);
    }

    #[Test]
    public function renderEscapesHtml(): void
    {
        $html = $this->block->render([
            'text' => 'Use <b>bold</b> & "quotes"',
        ]);

        self::assertStringContainsString('&lt;b&gt;', $html);
        self::assertStringContainsString('&amp;', $html);
        self::assertStringContainsString('&quot;quotes&quot;', $html);
    }

    #[Test]
    public function renderSupportsAnchorAndClassName(): void
    {
        $html = $this->block->render([
            'text' => 'Test',
            'anchor' => 'para-1',
            'className' => 'intro',
        ]);

        self::assertStringContainsString('id="para-1"', $html);
        self::assertStringContainsString('class="intro"', $html);
    }

    #[Test]
    public function validateReturnsErrorForMissingText(): void
    {
        $errors = $this->block->validate([]);

        self::assertStringContainsString('text is required', $errors[0]);
    }

    #[Test]
    public function validateReturnsErrorForInvalidAlignment(): void
    {
        $errors = $this->block->validate([
            'text' => 'Test',
            'alignment' => 'stretch',
        ]);

        self::assertNotEmpty($errors);
    }

    #[Test]
    public function validateReturnsEmptyForValidData(): void
    {
        self::assertSame([], $this->block->validate([
            'text' => 'Hello',
            'alignment' => 'center',
        ]));
    }
}
