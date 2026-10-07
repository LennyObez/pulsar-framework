<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\BlockEditor\CoreBlocks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
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

    #[Test]
    public function typeReturnsCta(): void
    {
        self::assertSame('cta', $this->block->type());
    }

    #[Test]
    public function rendersCtaButton(): void
    {
        $html = $this->block->render(['text' => 'Buy Now', 'url' => '/shop']);

        self::assertStringContainsString('<a href="/shop" class="cta-button">Buy Now</a>', $html);
        self::assertStringContainsString('application/ld+json', $html);
        self::assertStringContainsString('"@type":"ViewAction"', $html);
    }

    #[Test]
    public function rendersCtaWithStyle(): void
    {
        $html = $this->block->render([
            'text' => 'Sign Up',
            'url' => '/register',
            'style' => 'primary',
        ]);

        self::assertStringContainsString('cta-button cta-button--primary', $html);
    }

    #[Test]
    public function escapesXssInText(): void
    {
        $html = $this->block->render([
            'text' => '<script>xss</script>',
            'url' => '/shop',
        ]);

        // Anchor text is HTML-escaped; JSON-LD uses JSON_HEX_TAG so no raw <script> in either
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringNotContainsString('<script>xss', $html);
    }

    #[Test]
    public function escapesXssInUrl(): void
    {
        $html = $this->block->render([
            'text' => 'Buy',
            'url' => '" onclick="alert(1)',
        ]);

        // The double-quote is escaped to &quot;, preventing attribute injection
        self::assertStringContainsString('href="&quot;', $html);
    }

    #[Test]
    public function validatesRequiredText(): void
    {
        $errors = $this->block->validate(['url' => '/shop']);

        self::assertContains('text is required and must be a string', $errors);
    }

    #[Test]
    public function validatesRequiredUrl(): void
    {
        $errors = $this->block->validate(['text' => 'Buy']);

        self::assertContains('url is required and must be a string', $errors);
    }

    #[Test]
    public function validDataReturnsNoErrors(): void
    {
        $errors = $this->block->validate(['text' => 'Buy', 'url' => '/shop']);

        self::assertSame([], $errors);
    }
}
