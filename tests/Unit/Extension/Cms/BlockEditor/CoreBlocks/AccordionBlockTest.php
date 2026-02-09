<?php

declare(strict_types=1);

namespace Tests\Unit\Extension\Cms\BlockEditor\CoreBlocks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\AccordionBlock;

#[CoversClass(AccordionBlock::class)]
final class AccordionBlockTest extends TestCase
{
    private AccordionBlock $block;

    protected function setUp(): void
    {
        $this->block = new AccordionBlock();
    }

    #[Test]
    public function typeReturnsAccordion(): void
    {
        self::assertSame('accordion', $this->block->type());
    }

    #[Test]
    public function rendersAccordionWithRequiredFieldsOnly(): void
    {
        $html = $this->block->render([
            'items' => [
                ['title' => 'Section 1', 'content' => 'Content 1'],
                ['title' => 'Section 2', 'content' => 'Content 2'],
            ],
        ]);

        self::assertStringContainsString('class="accordion"', $html);
        self::assertStringContainsString('data-allow-multiple="false"', $html);
        self::assertStringContainsString('<details class="accordion__item">', $html);
        self::assertStringContainsString('<summary class="accordion__title">Section 1</summary>', $html);
        self::assertStringContainsString('<div class="accordion__content">Content 1</div>', $html);
        self::assertStringContainsString('<summary class="accordion__title">Section 2</summary>', $html);
    }

    #[Test]
    public function rendersWithAllowMultiple(): void
    {
        $html = $this->block->render([
            'items' => [
                ['title' => 'FAQ', 'content' => 'Answer'],
            ],
            'allowMultiple' => true,
        ]);

        self::assertStringContainsString('data-allow-multiple="true"', $html);
    }

    #[Test]
    public function defaultsAllowMultipleToFalse(): void
    {
        $html = $this->block->render([
            'items' => [
                ['title' => 'FAQ', 'content' => 'Answer'],
            ],
        ]);

        self::assertStringContainsString('data-allow-multiple="false"', $html);
    }

    #[Test]
    public function rendersDetailsAndSummaryTags(): void
    {
        $html = $this->block->render([
            'items' => [
                ['title' => 'Title', 'content' => 'Body'],
            ],
        ]);

        self::assertStringContainsString('<details class="accordion__item">', $html);
        self::assertStringContainsString('<summary class="accordion__title">', $html);
        self::assertStringContainsString('</details>', $html);
    }

    #[Test]
    public function escapesXssInTitleAndContent(): void
    {
        $html = $this->block->render([
            'items' => [
                ['title' => '<script>alert("xss")</script>', 'content' => '<img onerror="hack">'],
            ],
        ]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('onerror="hack"', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('onerror=&quot;hack&quot;', $html);
    }

    #[Test]
    public function validatesRequiredItems(): void
    {
        $errors = $this->block->validate([]);

        self::assertContains('items is required and must be an array', $errors);
    }

    #[Test]
    public function validatesItemsMustBeArray(): void
    {
        $errors = $this->block->validate(['items' => 'not-an-array']);

        self::assertContains('items is required and must be an array', $errors);
    }

    #[Test]
    public function validatesItemTitleRequired(): void
    {
        $errors = $this->block->validate([
            'items' => [['content' => 'Body']],
        ]);

        self::assertContains('items[0].title is required and must be a string', $errors);
    }

    #[Test]
    public function validatesItemContentRequired(): void
    {
        $errors = $this->block->validate([
            'items' => [['title' => 'Title']],
        ]);

        self::assertContains('items[0].content is required and must be a string', $errors);
    }

    #[Test]
    public function validatesItemMustBeObject(): void
    {
        $errors = $this->block->validate([
            'items' => ['not-an-object'],
        ]);

        self::assertContains('items[0] must be an object', $errors);
    }

    #[Test]
    public function validDataReturnsNoErrors(): void
    {
        $errors = $this->block->validate([
            'items' => [
                ['title' => 'FAQ', 'content' => 'Answer'],
            ],
        ]);

        self::assertSame([], $errors);
    }
}
