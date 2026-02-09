<?php

declare(strict_types=1);

namespace Tests\Unit\Extension\Cms\BlockEditor\CoreBlocks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\EmbedBlock;

#[CoversClass(EmbedBlock::class)]
final class EmbedBlockTest extends TestCase
{
    private EmbedBlock $block;

    protected function setUp(): void
    {
        $this->block = new EmbedBlock();
    }

    #[Test]
    public function typeReturnsEmbed(): void
    {
        self::assertSame('embed', $this->block->type());
    }

    #[Test]
    public function rendersIframeByDefault(): void
    {
        $html = $this->block->render(['url' => 'https://example.com/video']);

        self::assertStringContainsString('<div class="embed">', $html);
        self::assertStringContainsString('<iframe', $html);
        self::assertStringContainsString('sandbox=', $html);
    }

    #[Test]
    public function rendersCustomHtmlWhenProvided(): void
    {
        $html = $this->block->render([
            'url' => 'https://example.com',
            'html' => '<iframe src="https://example.com"></iframe>',
        ]);

        self::assertStringContainsString('<iframe src="https://example.com"></iframe>', $html);
    }

    #[Test]
    public function sanitizesCustomHtmlStrippingDangerousTags(): void
    {
        $html = $this->block->render([
            'url' => 'https://example.com',
            'html' => '<script>alert(1)</script><iframe src="ok"></iframe>',
        ]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('<iframe src="ok"></iframe>', $html);
    }

    #[Test]
    public function validatesRequiredUrl(): void
    {
        $errors = $this->block->validate([]);

        self::assertContains('url is required and must be a string', $errors);
    }

    #[Test]
    public function validatesUrlProtocol(): void
    {
        $errors = $this->block->validate(['url' => 'javascript:alert(1)']);

        self::assertContains('url must be a valid HTTP or HTTPS URL', $errors);
    }

    #[Test]
    public function validDataReturnsNoErrors(): void
    {
        $errors = $this->block->validate(['url' => 'https://youtube.com/watch?v=123']);

        self::assertSame([], $errors);
    }
}
