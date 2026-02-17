<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\BlockEditor\CoreBlocks;

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
    public function renderOutputsSandboxedIframe(): void
    {
        $html = $this->block->render([
            'url' => 'https://www.youtube.com/embed/test',
        ]);

        self::assertStringContainsString('iframe', $html);
        self::assertStringContainsString('sandbox="allow-scripts allow-popups"', $html);
        self::assertStringContainsString('src="https://www.youtube.com/embed/test"', $html);
    }

    #[Test]
    public function renderCustomHtmlUsesSrcdocSandbox(): void
    {
        $html = $this->block->render([
            'url' => 'https://example.com',
            'html' => '<p>Custom embed</p>',
        ]);

        self::assertStringContainsString('srcdoc=', $html);
        self::assertStringContainsString('sandbox="allow-scripts"', $html);
        self::assertStringNotContainsString('allow-same-origin', $html);
    }

    #[Test]
    public function renderEscapesUrlInAttribute(): void
    {
        $html = $this->block->render([
            'url' => 'https://example.com/embed?a=1&b=2',
        ]);

        self::assertStringContainsString('a=1&amp;b=2', $html);
    }

    #[Test]
    public function validateReturnsErrorForMissingUrl(): void
    {
        $errors = $this->block->validate([]);

        self::assertStringContainsString('url is required', $errors[0]);
    }

    #[Test]
    public function validateReturnsErrorForNonHttpUrl(): void
    {
        $errors = $this->block->validate([
            'url' => 'javascript:alert(1)',
        ]);

        self::assertStringContainsString('valid HTTP or HTTPS URL', $errors[0]);
    }

    #[Test]
    public function validateAcceptsHttpsUrl(): void
    {
        self::assertSame([], $this->block->validate([
            'url' => 'https://www.youtube.com/embed/abc',
        ]));
    }

    #[Test]
    public function validateAcceptsHttpUrl(): void
    {
        self::assertSame([], $this->block->validate([
            'url' => 'http://example.com/widget',
        ]));
    }
}
