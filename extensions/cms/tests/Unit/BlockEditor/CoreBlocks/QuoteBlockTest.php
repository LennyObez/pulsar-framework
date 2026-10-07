<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\BlockEditor\CoreBlocks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\QuoteBlock;

#[CoversClass(QuoteBlock::class)]
final class QuoteBlockTest extends TestCase
{
    private QuoteBlock $block;

    protected function setUp(): void
    {
        $this->block = new QuoteBlock();
    }

    #[Test]
    public function typeReturnsQuote(): void
    {
        self::assertSame('quote', $this->block->type());
    }

    #[Test]
    public function renderOutputsBlockquote(): void
    {
        $html = $this->block->render(['text' => 'To be or not to be']);

        self::assertStringContainsString('<blockquote', $html);
        self::assertStringContainsString('To be or not to be', $html);
    }

    #[Test]
    public function renderIncludesCitation(): void
    {
        $html = $this->block->render([
            'text' => 'Knowledge is power',
            'citation' => 'Francis Bacon',
        ]);

        self::assertStringContainsString('<cite>Francis Bacon</cite>', $html);
    }

    #[Test]
    public function renderOmitsCitationWhenEmpty(): void
    {
        $html = $this->block->render([
            'text' => 'Test',
            'citation' => '',
        ]);

        self::assertStringNotContainsString('cite', $html);
    }

    #[Test]
    public function renderEscapesHtml(): void
    {
        $html = $this->block->render([
            'text' => '<script>alert(1)</script>',
        ]);

        self::assertStringNotContainsString('<script>', $html);
    }

    #[Test]
    public function renderSupportsAnchorAndClassName(): void
    {
        $html = $this->block->render([
            'text' => 'Quote',
            'anchor' => 'famous-quote',
            'className' => 'highlight',
        ]);

        self::assertStringContainsString('id="famous-quote"', $html);
        self::assertStringContainsString('class="highlight"', $html);
    }

    #[Test]
    public function validateReturnsErrorForMissingText(): void
    {
        $errors = $this->block->validate([]);

        self::assertStringContainsString('text is required', $errors[0]);
    }

    #[Test]
    public function validateReturnsErrorForNonStringCitation(): void
    {
        $errors = $this->block->validate([
            'text' => 'Quote',
            'citation' => 42,
        ]);

        self::assertStringContainsString('citation must be a string', $errors[0]);
    }

    #[Test]
    public function validateReturnsEmptyForValidData(): void
    {
        self::assertSame([], $this->block->validate([
            'text' => 'Test',
            'citation' => 'Author',
        ]));
    }
}
