<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\BlockEditor\CoreBlocks;

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
    public function renderOutputsPreCodeElement(): void
    {
        $html = $this->block->render([
            'code' => 'echo "hello";',
        ]);

        self::assertStringContainsString('<pre><code>', $html);
        self::assertStringContainsString('echo &quot;hello&quot;;', $html);
    }

    #[Test]
    public function renderAddsLanguageClass(): void
    {
        $html = $this->block->render([
            'code' => '$x = 1;',
            'language' => 'php',
        ]);

        self::assertStringContainsString('language-php', $html);
    }

    #[Test]
    public function renderIgnoresInvalidLanguageCharacters(): void
    {
        $html = $this->block->render([
            'code' => 'test',
            'language' => 'c++/bad',
        ]);

        self::assertStringNotContainsString('language-', $html);
    }

    #[Test]
    public function renderEscapesHtmlInCode(): void
    {
        $html = $this->block->render([
            'code' => '<script>alert("xss")</script>',
        ]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    public function validateReturnsErrorWhenCodeMissing(): void
    {
        $errors = $this->block->validate([]);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('code is required', $errors[0]);
    }

    #[Test]
    public function validateReturnsErrorForNonStringLanguage(): void
    {
        $errors = $this->block->validate([
            'code' => 'test',
            'language' => 42,
        ]);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('language must be a string', $errors[0]);
    }

    #[Test]
    public function validateReturnsEmptyForValidData(): void
    {
        self::assertSame([], $this->block->validate(['code' => 'x = 1', 'language' => 'python']));
    }
}
