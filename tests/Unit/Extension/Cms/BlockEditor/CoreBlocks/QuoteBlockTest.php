<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\BlockEditor\CoreBlocks;

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
    public function rendersQuoteWithoutCitation(): void
    {
        $html = $this->block->render(['text' => 'To be or not to be.']);

        self::assertSame('<blockquote><p>To be or not to be.</p></blockquote>', $html);
    }

    #[Test]
    public function rendersQuoteWithCitation(): void
    {
        $html = $this->block->render([
            'text' => 'To be or not to be.',
            'citation' => 'Shakespeare',
        ]);

        self::assertStringContainsString('<cite>Shakespeare</cite>', $html);
    }

    #[Test]
    public function escapesXssInText(): void
    {
        $html = $this->block->render(['text' => '<script>xss</script>']);

        self::assertStringNotContainsString('<script>', $html);
    }

    #[Test]
    public function validatesRequiredText(): void
    {
        $errors = $this->block->validate([]);

        self::assertContains('text is required and must be a string', $errors);
    }

    #[Test]
    public function validDataReturnsNoErrors(): void
    {
        $errors = $this->block->validate(['text' => 'A quote', 'citation' => 'Author']);

        self::assertSame([], $errors);
    }
}
