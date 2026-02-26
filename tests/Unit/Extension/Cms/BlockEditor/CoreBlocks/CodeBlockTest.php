<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\BlockEditor\CoreBlocks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\CodeBlock;

#[CoversClass(CodeBlock::class)]
final class CodeBlockTest extends TestCase
{
    private CodeBlock $block;

    protected function setUp(): void
    {
        $this->block = new CodeBlock();
    }

    #[Test]
    public function typeReturnsCode(): void
    {
        self::assertSame('code', $this->block->type());
    }

    #[Test]
    public function rendersCodeWithoutLanguage(): void
    {
        $html = $this->block->render(['code' => 'echo "hello";']);

        self::assertSame('<pre><code>echo &quot;hello&quot;;</code></pre>', $html);
    }

    #[Test]
    public function rendersCodeWithLanguage(): void
    {
        $html = $this->block->render(['code' => '$x = 1;', 'language' => 'php']);

        self::assertStringContainsString('class="language-php"', $html);
        self::assertStringContainsString('$x = 1;', $html);
    }

    #[Test]
    public function escapesXssInCode(): void
    {
        $html = $this->block->render(['code' => '<script>alert(1)</script>']);

        self::assertStringNotContainsString('<script>alert', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    public function rejectsInvalidLanguageChars(): void
    {
        $html = $this->block->render(['code' => 'x', 'language' => '<script>']);

        self::assertStringNotContainsString('language-<script>', $html);
        self::assertSame('<pre><code>x</code></pre>', $html);
    }

    #[Test]
    public function validatesRequiredCode(): void
    {
        $errors = $this->block->validate([]);

        self::assertContains('code is required and must be a string', $errors);
    }

    #[Test]
    public function validatesLanguageMustBeString(): void
    {
        $errors = $this->block->validate(['code' => 'x', 'language' => 42]);

        self::assertContains('language must be a string', $errors);
    }

    #[Test]
    public function validDataReturnsNoErrors(): void
    {
        $errors = $this->block->validate(['code' => 'echo 1;', 'language' => 'php']);

        self::assertSame([], $errors);
    }
}
