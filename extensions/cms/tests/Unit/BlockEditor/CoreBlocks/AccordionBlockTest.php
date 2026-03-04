<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\BlockEditor\CoreBlocks;

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
    public function schemaRequiresItems(): void
    {
        $schema = $this->block->schema();

        self::assertSame(['items'], $schema['required']);
    }

    #[Test]
    public function renderOutputsDetailsElements(): void
    {
        $html = $this->block->render([
            'items' => [
                ['title' => 'Q1', 'content' => 'Answer 1'],
                ['title' => 'Q2', 'content' => 'Answer 2'],
            ],
        ]);

        self::assertStringContainsString('<details class="accordion__item">', $html);
        self::assertStringContainsString('Q1</summary>', $html);
        self::assertStringContainsString('Answer 1</div>', $html);
        self::assertStringContainsString('Q2</summary>', $html);
        self::assertStringContainsString('accordion-heading-0', $html);
        self::assertStringContainsString('accordion-panel-1', $html);
    }

    #[Test]
    public function renderIncludesFaqStructuredData(): void
    {
        $html = $this->block->render([
            'items' => [
                ['title' => 'Question', 'content' => 'Answer'],
            ],
        ]);

        self::assertStringContainsString('application/ld+json', $html);
        self::assertStringContainsString('FAQPage', $html);
    }

    #[Test]
    public function renderSkipsEmptyItems(): void
    {
        $html = $this->block->render([
            'items' => [],
        ]);

        self::assertStringNotContainsString('accordion__item', $html);
        self::assertStringNotContainsString('ld+json', $html);
    }

    #[Test]
    public function renderEscapesHtmlInSummaryElement(): void
    {
        $html = $this->block->render([
            'items' => [
                ['title' => '<script>alert("xss")</script>', 'content' => 'safe'],
            ],
        ]);

        // The summary element must contain the escaped version
        self::assertStringContainsString('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;</summary>', $html);
    }

    #[Test]
    public function renderSupportsAnchorAndClassName(): void
    {
        $html = $this->block->render([
            'items' => [['title' => 'T', 'content' => 'C']],
            'anchor' => 'faq-section',
            'className' => 'custom-class',
        ]);

        self::assertStringContainsString('id="faq-section"', $html);
        self::assertStringContainsString('custom-class', $html);
    }

    #[Test]
    public function renderSetsAllowMultipleAttribute(): void
    {
        $html = $this->block->render([
            'items' => [['title' => 'T', 'content' => 'C']],
            'allowMultiple' => true,
        ]);

        self::assertStringContainsString('data-allow-multiple="true"', $html);
    }

    #[Test]
    public function renderSkipsNonArrayItems(): void
    {
        $html = $this->block->render([
            'items' => ['not-an-array', 42],
        ]);

        self::assertStringNotContainsString('accordion__item', $html);
    }

    #[Test]
    public function validateReturnsErrorWhenItemsMissing(): void
    {
        $errors = $this->block->validate([]);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('items is required', $errors[0]);
    }

    #[Test]
    public function validateReturnsErrorForNonArrayItems(): void
    {
        $errors = $this->block->validate([
            'items' => [42, 'string'],
        ]);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('items[0] must be an object', $errors[0]);
    }

    #[Test]
    public function validateReturnsErrorForMissingTitleAndContent(): void
    {
        $errors = $this->block->validate([
            'items' => [
                ['title' => 123, 'content' => null],
            ],
        ]);

        self::assertNotEmpty($errors);
    }

    #[Test]
    public function validateReturnsEmptyForValidData(): void
    {
        $errors = $this->block->validate([
            'items' => [
                ['title' => 'Q', 'content' => 'A'],
            ],
        ]);

        self::assertSame([], $errors);
    }
}
