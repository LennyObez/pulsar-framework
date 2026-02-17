<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\BlockEditor\CoreBlocks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\CtaBlock;

#[CoversClass(CtaBlock::class)]
final class CtaBlockTest extends TestCase
{
    private CtaBlock $block;

    protected function setUp(): void
    {
        $this->block = new CtaBlock();
    }

    public function testType(): void
    {
        self::assertSame('cta', $this->block->type());
    }

    public function testSchemaIncludesAnchorAndClassName(): void
    {
        $schema = $this->block->schema();

        /** @var array<string, mixed> $properties */
        $properties = $schema['properties'];
        self::assertArrayHasKey('anchor', $properties);
        self::assertArrayHasKey('className', $properties);
    }

    public function testRenderBasic(): void
    {
        $html = $this->block->render([
            'text' => 'Click Me',
            'url' => 'https://example.com',
        ]);

        self::assertStringContainsString('Click Me', $html);
        self::assertStringContainsString('href="https://example.com"', $html);
        self::assertStringContainsString('cta-button', $html);
    }

    public function testRenderWithStyle(): void
    {
        $html = $this->block->render([
            'text' => 'CTA',
            'url' => '/page',
            'style' => 'primary',
        ]);

        self::assertStringContainsString('cta-button--primary', $html);
    }

    public function testRenderWithAnchor(): void
    {
        $html = $this->block->render([
            'text' => 'CTA',
            'url' => '/page',
            'anchor' => 'main-cta',
        ]);

        self::assertStringContainsString('id="main-cta"', $html);
    }

    public function testRenderWithClassName(): void
    {
        $html = $this->block->render([
            'text' => 'CTA',
            'url' => '/page',
            'className' => 'my-custom-class',
        ]);

        self::assertStringContainsString('my-custom-class', $html);
    }

    public function testRenderIncludesJsonLd(): void
    {
        $html = $this->block->render([
            'text' => 'Get Started',
            'url' => 'https://example.com/signup',
        ]);

        self::assertStringContainsString('application/ld+json', $html);
        self::assertStringContainsString('"@type":"WebPage"', $html);
        self::assertStringContainsString('"@type":"ViewAction"', $html);
        self::assertStringContainsString('https://example.com/signup', $html);
        self::assertStringContainsString('Get Started', $html);
    }

    public function testRenderNoJsonLdForEmptyUrl(): void
    {
        $html = $this->block->render([
            'text' => 'CTA',
            'url' => '',
        ]);

        self::assertStringNotContainsString('application/ld+json', $html);
    }

    public function testRenderEscapesHtml(): void
    {
        $html = $this->block->render([
            'text' => '<script>alert("xss")</script>',
            'url' => 'https://example.com',
        ]);

        // The anchor text is HTML-escaped
        self::assertStringContainsString('&lt;script&gt;', $html);
        // JSON-LD uses JSON_HEX_TAG so <> become \u003C and \u003E: no raw <script> tags
        self::assertStringNotContainsString('<script>alert', $html);
    }

    public function testValidateRequiresText(): void
    {
        $errors = $this->block->validate(['url' => '/page']);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('text', $errors[0]);
    }

    public function testValidateRequiresUrl(): void
    {
        $errors = $this->block->validate(['text' => 'CTA']);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('url', $errors[0]);
    }

    public function testValidateAcceptsValidData(): void
    {
        $errors = $this->block->validate([
            'text' => 'Click',
            'url' => 'https://example.com',
        ]);

        self::assertEmpty($errors);
    }
}
