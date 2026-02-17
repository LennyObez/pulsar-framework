<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\BlockEditor\CoreBlocks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\CodeComparisonBlock;

#[CoversClass(CodeComparisonBlock::class)]
final class CodeComparisonBlockTest extends TestCase
{
    private CodeComparisonBlock $block;

    protected function setUp(): void
    {
        $this->block = new CodeComparisonBlock();
    }

    public function testType(): void
    {
        self::assertSame('code-comparison', $this->block->type());
    }

    public function testRenderSideBySide(): void
    {
        $html = $this->block->render([
            'leftLabel' => 'Pulsar',
            'leftCode' => '$router->get("/hello", fn() => "Hi");',
            'leftLanguage' => 'php',
            'rightLabel' => 'Laravel',
            'rightCode' => 'Route::get("/hello", fn() => "Hi");',
            'rightLanguage' => 'php',
        ]);

        self::assertStringContainsString('code-comparison-block__panels', $html);
        self::assertStringContainsString('>Pulsar</div>', $html);
        self::assertStringContainsString('>Laravel</div>', $html);
        self::assertStringContainsString('language-php', $html);
        self::assertStringContainsString('<pre><code', $html);
    }

    public function testRenderWithTitle(): void
    {
        $html = $this->block->render([
            'title' => 'Route Definition',
            'leftLabel' => 'A',
            'leftCode' => 'a',
            'rightLabel' => 'B',
            'rightCode' => 'b',
        ]);

        self::assertStringContainsString('Route Definition', $html);
    }

    public function testRenderWithoutLanguage(): void
    {
        $html = $this->block->render([
            'leftLabel' => 'A',
            'leftCode' => 'code',
            'rightLabel' => 'B',
            'rightCode' => 'code',
        ]);

        self::assertStringNotContainsString('language-', $html);
    }

    public function testRenderSanitizesInvalidLanguage(): void
    {
        $html = $this->block->render([
            'leftLabel' => 'A',
            'leftCode' => 'code',
            'leftLanguage' => '<script>alert(1)</script>',
            'rightLabel' => 'B',
            'rightCode' => 'code',
        ]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('language-<', $html);
    }

    public function testRenderEscapesCode(): void
    {
        $html = $this->block->render([
            'leftLabel' => 'A',
            'leftCode' => '<div class="test">',
            'rightLabel' => 'B',
            'rightCode' => '&amp; special',
        ]);

        self::assertStringContainsString('&lt;div class=&quot;test&quot;&gt;', $html);
    }

    public function testValidateRequiresAllFields(): void
    {
        $errors = $this->block->validate([]);

        self::assertContains('leftLabel is required and must be a string', $errors);
        self::assertContains('leftCode is required and must be a string', $errors);
        self::assertContains('rightLabel is required and must be a string', $errors);
        self::assertContains('rightCode is required and must be a string', $errors);
    }

    public function testValidateAcceptsValidData(): void
    {
        $errors = $this->block->validate([
            'leftLabel' => 'A',
            'leftCode' => 'code',
            'rightLabel' => 'B',
            'rightCode' => 'code',
        ]);

        self::assertSame([], $errors);
    }
}
