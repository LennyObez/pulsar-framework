<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\BlockEditor\CoreBlocks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\ParagraphBlock;

#[CoversClass(ParagraphBlock::class)]
final class ParagraphBlockTest extends TestCase
{
    private ParagraphBlock $block;

    protected function setUp(): void
    {
        $this->block = new ParagraphBlock();
    }

    #[Test]
    public function typeReturnsParagraph(): void
    {
        self::assertSame('paragraph', $this->block->type());
    }

    #[Test]
    public function rendersSimpleParagraph(): void
    {
        $html = $this->block->render(['text' => 'Hello world']);

        self::assertSame('<p>Hello world</p>', $html);
    }

    #[Test]
    public function rendersWithAlignment(): void
    {
        $html = $this->block->render(['text' => 'Centered text', 'alignment' => 'center']);

        self::assertSame('<p style="text-align:center">Centered text</p>', $html);
    }

    #[Test]
    public function ignoresInvalidAlignment(): void
    {
        $html = $this->block->render(['text' => 'Test', 'alignment' => 'invalid']);

        self::assertSame('<p>Test</p>', $html);
    }

    #[Test]
    public function escapesXssInText(): void
    {
        $html = $this->block->render(['text' => '<script>alert("xss")</script>']);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    public function validatesRequiredText(): void
    {
        $errors = $this->block->validate([]);

        self::assertContains('text is required and must be a string', $errors);
    }

    #[Test]
    public function validatesTextIsString(): void
    {
        $errors = $this->block->validate(['text' => 123]);

        self::assertContains('text is required and must be a string', $errors);
    }

    #[Test]
    public function validatesInvalidAlignment(): void
    {
        $errors = $this->block->validate(['text' => 'ok', 'alignment' => 'stretch']);

        self::assertContains('alignment must be one of: left, center, right, justify', $errors);
    }

    #[Test]
    public function validDataReturnsNoErrors(): void
    {
        $errors = $this->block->validate(['text' => 'Hello', 'alignment' => 'left']);

        self::assertSame([], $errors);
    }

    #[Test]
    public function schemaReturnsValidStructure(): void
    {
        $schema = $this->block->schema();

        self::assertSame('object', $schema['type']);
        self::assertIsArray($schema['properties']);
        self::assertArrayHasKey('text', $schema['properties']);
        self::assertIsArray($schema['required']);
        self::assertContains('text', $schema['required']);
    }
}
