<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\BlockEditor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\AccordionBlock;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\AlertBlock;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\CarouselBlock;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\EmbedBlock;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\HeadingBlock;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\ImageBlock;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\ParagraphBlock;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\QuoteBlock;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\TabsBlock;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\VideoBlock;

#[CoversClass(AccordionBlock::class)]
#[CoversClass(AlertBlock::class)]
#[CoversClass(CarouselBlock::class)]
#[CoversClass(HeadingBlock::class)]
#[CoversClass(ImageBlock::class)]
#[CoversClass(ParagraphBlock::class)]
#[CoversClass(QuoteBlock::class)]
#[CoversClass(TabsBlock::class)]
#[CoversClass(VideoBlock::class)]
#[CoversClass(EmbedBlock::class)]
final class BlockAccessibilityTest extends TestCase
{
    // --- AccordionBlock ARIA ---

    public function testAccordionHasAriaControls(): void
    {
        $block = new AccordionBlock();
        $html = $block->render(['items' => [['title' => 'Q1', 'content' => 'A1']]]);

        self::assertStringContainsString('aria-controls="accordion-panel-0"', $html);
        self::assertStringContainsString('role="region"', $html);
        self::assertStringContainsString('aria-labelledby="accordion-heading-0"', $html);
    }

    public function testAccordionHasFaqStructuredData(): void
    {
        $block = new AccordionBlock();
        $html = $block->render(['items' => [['title' => 'Q1', 'content' => 'A1']]]);

        self::assertStringContainsString('application/ld+json', $html);
        self::assertStringContainsString('FAQPage', $html);
        self::assertStringContainsString('Question', $html);
    }

    public function testAccordionSupportsAnchorAndClassName(): void
    {
        $block = new AccordionBlock();
        $html = $block->render([
            'items' => [['title' => 'T', 'content' => 'C']],
            'anchor' => 'faq-section',
            'className' => 'custom-faq',
        ]);

        self::assertStringContainsString('id="faq-section"', $html);
        self::assertStringContainsString('custom-faq', $html);
    }

    // --- AlertBlock ARIA ---

    public function testAlertBlockHasCorrectRole(): void
    {
        $block = new AlertBlock();

        $errorHtml = $block->render(['message' => 'Error!', 'alertType' => 'error']);
        self::assertStringContainsString('role="alert"', $errorHtml);

        $infoHtml = $block->render(['message' => 'Info', 'alertType' => 'info']);
        self::assertStringContainsString('role="status"', $infoHtml);
    }

    public function testAlertBlockSupportsAnchorAndClassName(): void
    {
        $block = new AlertBlock();
        $html = $block->render([
            'message' => 'Notice',
            'alertType' => 'warning',
            'anchor' => 'alert-1',
            'className' => 'custom-alert',
        ]);

        self::assertStringContainsString('id="alert-1"', $html);
        self::assertStringContainsString('custom-alert', $html);
    }

    // --- CarouselBlock ARIA ---

    public function testCarouselHasAriaRoledescription(): void
    {
        $block = new CarouselBlock();
        $html = $block->render(['slides' => [
            ['imageUrl' => '/img.jpg', 'alt' => 'Slide 1'],
            ['imageUrl' => '/img2.jpg', 'alt' => 'Slide 2'],
        ]]);

        self::assertStringContainsString('aria-roledescription="carousel"', $html);
        self::assertStringContainsString('aria-roledescription="slide"', $html);
        self::assertStringContainsString('aria-label="Slide 1 of 2"', $html);
        self::assertStringContainsString('aria-label="Slide 2 of 2"', $html);
        self::assertStringContainsString('aria-live="polite"', $html);
        self::assertStringContainsString('aria-label="Slide navigation"', $html);
    }

    public function testCarouselSupportsAnchorAndClassName(): void
    {
        $block = new CarouselBlock();
        $html = $block->render([
            'slides' => [['imageUrl' => '/img.jpg', 'alt' => 'Test']],
            'anchor' => 'gallery-1',
            'className' => 'wide-carousel',
        ]);

        self::assertStringContainsString('id="gallery-1"', $html);
        self::assertStringContainsString('wide-carousel', $html);
    }

    // --- TabsBlock ARIA ---

    public function testTabsBlockHasAriaRoles(): void
    {
        $block = new TabsBlock();
        $html = $block->render(['tabs' => [
            ['title' => 'Tab 1', 'content' => 'Content 1'],
            ['title' => 'Tab 2', 'content' => 'Content 2'],
        ]]);

        self::assertStringContainsString('role="tablist"', $html);
        self::assertStringContainsString('role="tab"', $html);
        self::assertStringContainsString('role="tabpanel"', $html);
        self::assertStringContainsString('aria-selected="true"', $html);
        self::assertStringContainsString('aria-selected="false"', $html);
        self::assertStringContainsString('aria-controls="panel-0"', $html);
        self::assertStringContainsString('aria-labelledby="tab-0"', $html);
    }

    public function testTabsBlockSupportsAnchorAndClassName(): void
    {
        $block = new TabsBlock();
        $html = $block->render([
            'tabs' => [['title' => 'T', 'content' => 'C']],
            'anchor' => 'settings-tabs',
            'className' => 'full-width',
        ]);

        self::assertStringContainsString('id="settings-tabs"', $html);
        self::assertStringContainsString('full-width', $html);
    }

    // --- HeadingBlock ---

    public function testHeadingAutoGeneratesAnchorId(): void
    {
        $block = new HeadingBlock();
        $html = $block->render(['text' => 'Getting Started', 'level' => 2]);

        self::assertStringContainsString('id="getting-started"', $html);
    }

    public function testHeadingUsesCustomAnchor(): void
    {
        $block = new HeadingBlock();
        $html = $block->render(['text' => 'Title', 'level' => 1, 'anchor' => 'custom-id']);

        self::assertStringContainsString('id="custom-id"', $html);
    }

    public function testHeadingSupportsClassName(): void
    {
        $block = new HeadingBlock();
        $html = $block->render(['text' => 'Title', 'level' => 2, 'className' => 'hero-heading']);

        self::assertStringContainsString('class="hero-heading"', $html);
    }

    // --- ImageBlock SEO ---

    public function testImageBlockHasStructuredData(): void
    {
        $block = new ImageBlock();
        $html = $block->render(['src' => 'https://example.com/img.jpg', 'alt' => 'A photo']);

        self::assertStringContainsString('application/ld+json', $html);
        self::assertStringContainsString('ImageObject', $html);
        self::assertStringContainsString('https://example.com/img.jpg', $html);
    }

    public function testImageBlockSupportsAnchor(): void
    {
        $block = new ImageBlock();
        $html = $block->render(['src' => '/img.jpg', 'alt' => 'Test', 'anchor' => 'hero-image']);

        self::assertStringContainsString('id="hero-image"', $html);
    }

    // --- VideoBlock SEO ---

    public function testVideoBlockHasStructuredData(): void
    {
        $block = new VideoBlock();
        $html = $block->render([
            'src' => 'https://example.com/video.mp4',
            'caption' => 'Demo video',
            'poster' => 'https://example.com/poster.jpg',
        ]);

        self::assertStringContainsString('application/ld+json', $html);
        self::assertStringContainsString('VideoObject', $html);
        self::assertStringContainsString('Demo video', $html);
        self::assertStringContainsString('thumbnailUrl', $html);
    }

    public function testVideoBlockSupportsAnchorAndClassName(): void
    {
        $block = new VideoBlock();
        $html = $block->render([
            'src' => '/video.mp4',
            'anchor' => 'intro-video',
            'className' => 'featured',
        ]);

        self::assertStringContainsString('id="intro-video"', $html);
        self::assertStringContainsString('featured', $html);
    }

    // --- ParagraphBlock ---

    public function testParagraphSupportsAnchorAndClassName(): void
    {
        $block = new ParagraphBlock();
        $html = $block->render([
            'text' => 'Hello world',
            'anchor' => 'intro',
            'className' => 'lead',
        ]);

        self::assertStringContainsString('id="intro"', $html);
        self::assertStringContainsString('class="lead"', $html);
    }

    // --- QuoteBlock ---

    public function testQuoteSupportsAnchorAndClassName(): void
    {
        $block = new QuoteBlock();
        $html = $block->render([
            'text' => 'Notable quote',
            'anchor' => 'quote-1',
            'className' => 'featured-quote',
        ]);

        self::assertStringContainsString('id="quote-1"', $html);
        self::assertStringContainsString('class="featured-quote"', $html);
    }

    // --- EmbedBlock ---

    public function testEmbedSupportsAnchorAndClassName(): void
    {
        $block = new EmbedBlock();
        $html = $block->render([
            'url' => 'https://example.com',
            'anchor' => 'embed-1',
            'className' => 'wide',
        ]);

        self::assertStringContainsString('id="embed-1"', $html);
        self::assertStringContainsString('wide', $html);
    }
}
