<?php

declare(strict_types=1);

namespace Tests\Unit\Extension\Cms\BlockEditor\CoreBlocks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\HtmlBlock;
use Pulsar\Extension\Cms\Content\SafeHtmlPolicy;

#[CoversClass(HtmlBlock::class)]
final class HtmlBlockTest extends TestCase
{
    private HtmlBlock $block;

    protected function setUp(): void
    {
        $auditLogger = $this->createStub(AuditLoggerInterface::class);
        $policy = new SafeHtmlPolicy($auditLogger);
        $this->block = new HtmlBlock($policy);
    }

    #[Test]
    public function typeReturnsHtml(): void
    {
        self::assertSame('html', $this->block->type());
    }

    #[Test]
    public function rendersSanitizedHtml(): void
    {
        $html = $this->block->render([
            'html' => '<p>Hello <strong>world</strong></p>',
        ]);

        self::assertStringContainsString('<p>Hello <strong>world</strong></p>', $html);
    }

    #[Test]
    public function stripsScriptTags(): void
    {
        $html = $this->block->render([
            'html' => '<p>Safe</p><script>alert("xss")</script>',
        ]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('<p>Safe</p>', $html);
    }

    #[Test]
    public function stripsIframeTags(): void
    {
        $html = $this->block->render([
            'html' => '<p>Content</p><iframe src="evil.com"></iframe>',
        ]);

        self::assertStringNotContainsString('<iframe', $html);
        self::assertStringContainsString('<p>Content</p>', $html);
    }

    #[Test]
    public function allowsSafeTags(): void
    {
        $html = $this->block->render([
            'html' => '<h2>Title</h2><ul><li>Item</li></ul>',
        ]);

        self::assertStringContainsString('<h2>Title</h2>', $html);
        self::assertStringContainsString('<ul><li>Item</li></ul>', $html);
    }

    #[Test]
    public function stripsDisallowedTagsPreservingContent(): void
    {
        $html = $this->block->render([
            'html' => '<h1>Big Title</h1>',
        ]);

        // h1 is not in SafeHtmlPolicy allowlist — tag is unwrapped, content preserved
        self::assertStringNotContainsString('<h1>', $html);
        self::assertStringContainsString('Big Title', $html);
    }

    #[Test]
    public function validatesMissingHtml(): void
    {
        $errors = $this->block->validate([]);

        self::assertContains('html is required and must be a string', $errors);
    }

    #[Test]
    public function validatesHtmlMustBeString(): void
    {
        $errors = $this->block->validate(['html' => 123]);

        self::assertContains('html is required and must be a string', $errors);
    }

    #[Test]
    public function validDataReturnsNoErrors(): void
    {
        $errors = $this->block->validate(['html' => '<p>Valid HTML</p>']);

        self::assertSame([], $errors);
    }
}
