<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\BlockEditor\CoreBlocks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\BlockRenderer;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeInterface;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeRegistry;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\ColumnsBlock;

#[CoversClass(ColumnsBlock::class)]
final class ColumnsBlockTest extends TestCase
{
    private BlockTypeRegistry $registry;
    private BlockRenderer $renderer;

    protected function setUp(): void
    {
        $this->registry = new BlockTypeRegistry();
        $this->renderer = new BlockRenderer($this->registry);
    }

    private function createColumnsBlock(): ColumnsBlock
    {
        return new ColumnsBlock($this->renderer);
    }

    private function registerStubBlock(string $type, string $output): void
    {
        $stub = $this->createStub(BlockTypeInterface::class);
        $stub->method('type')->willReturn($type);
        $stub->method('validate')->willReturn([]);
        $stub->method('render')->willReturn($output);

        $this->registry->register($stub);
    }

    #[Test]
    public function typeReturnsColumns(): void
    {
        $block = $this->createColumnsBlock();

        self::assertSame('columns', $block->type());
    }

    #[Test]
    public function rendersColumnsWithNestedBlocks(): void
    {
        $this->registerStubBlock('paragraph', '<p>Hello</p>');

        $block = $this->createColumnsBlock();

        $html = $block->render([
            'columns' => [
                ['blocks' => [['blockType' => 'paragraph', 'data' => ['text' => 'Hello']]]],
                ['blocks' => [['blockType' => 'paragraph', 'data' => ['text' => 'World']]]],
            ],
        ]);

        self::assertStringContainsString('class="columns"', $html);
        self::assertStringContainsString('repeat(2,1fr)', $html);
        self::assertStringContainsString('class="column"', $html);
        self::assertStringContainsString('<p>Hello</p>', $html);
    }

    #[Test]
    public function rendersMultipleBlocksPerColumn(): void
    {
        $this->registerStubBlock('paragraph', '<p>Text</p>');
        $this->registerStubBlock('heading', '<h2>Title</h2>');

        $block = $this->createColumnsBlock();

        $html = $block->render([
            'columns' => [
                ['blocks' => [
                    ['blockType' => 'heading', 'data' => ['text' => 'Title', 'level' => 2]],
                    ['blockType' => 'paragraph', 'data' => ['text' => 'Text']],
                ]],
            ],
        ]);

        self::assertStringContainsString('<h2>Title</h2>', $html);
        self::assertStringContainsString('<p>Text</p>', $html);
    }

    #[Test]
    public function rendersEmptyColumns(): void
    {
        $block = $this->createColumnsBlock();

        $html = $block->render(['columns' => []]);

        self::assertSame('<div class="columns"></div>', $html);
    }

    #[Test]
    public function rendersMissingColumnsKeyAsEmpty(): void
    {
        $block = $this->createColumnsBlock();

        $html = $block->render([]);

        self::assertSame('<div class="columns"></div>', $html);
    }

    #[Test]
    public function skipsNonArrayColumns(): void
    {
        $block = $this->createColumnsBlock();

        $html = $block->render(['columns' => ['not-an-array']]);

        self::assertStringContainsString('repeat(1,1fr)', $html);
        self::assertStringNotContainsString('class="column"', $html);
    }

    #[Test]
    public function skipsNonArrayBlocks(): void
    {
        $block = $this->createColumnsBlock();

        $html = $block->render([
            'columns' => [
                ['blocks' => ['not-a-block']],
            ],
        ]);

        self::assertStringContainsString('class="column"', $html);
    }

    #[Test]
    public function skipsBlocksWithEmptyBlockType(): void
    {
        $block = $this->createColumnsBlock();

        $html = $block->render([
            'columns' => [
                ['blocks' => [['blockType' => '', 'data' => []]]],
            ],
        ]);

        // Empty blockType blocks are skipped; column div is still rendered
        self::assertStringContainsString('class="column"', $html);
    }

    #[Test]
    public function unknownNestedBlockRendersAsComment(): void
    {
        // Do not register any block types — 'paragraph' will be unknown
        $block = $this->createColumnsBlock();

        $html = $block->render([
            'columns' => [
                ['blocks' => [['blockType' => 'paragraph', 'data' => ['text' => 'Hello']]]],
            ],
        ]);

        self::assertStringContainsString('<!-- unknown block type: paragraph -->', $html);
    }

    #[Test]
    public function schemaDescribesColumnsStructure(): void
    {
        $block = $this->createColumnsBlock();

        $schema = $block->schema();

        self::assertSame('object', $schema['type']);
        /** @var array<string, mixed> $properties */
        $properties = $schema['properties'];
        self::assertArrayHasKey('columns', $properties);
        /** @var list<string> $required */
        $required = $schema['required'];
        self::assertContains('columns', $required);
    }

    #[Test]
    public function validatesRequiredColumns(): void
    {
        $block = $this->createColumnsBlock();

        $errors = $block->validate([]);

        self::assertContains('columns is required and must be an array', $errors);
    }

    #[Test]
    public function validatesColumnsNotArray(): void
    {
        $block = $this->createColumnsBlock();

        $errors = $block->validate(['columns' => 'not-array']);

        self::assertContains('columns is required and must be an array', $errors);
    }

    #[Test]
    public function validatesColumnMustBeObject(): void
    {
        $block = $this->createColumnsBlock();

        $errors = $block->validate(['columns' => ['not-an-object']]);

        self::assertContains('columns[0] must be an object', $errors);
    }

    #[Test]
    public function validatesBlocksMustBeArray(): void
    {
        $block = $this->createColumnsBlock();

        $errors = $block->validate([
            'columns' => [
                ['not-blocks' => true],
            ],
        ]);

        self::assertContains('columns[0].blocks is required and must be an array', $errors);
    }

    #[Test]
    public function validatesBlockStructure(): void
    {
        $block = $this->createColumnsBlock();

        $errors = $block->validate([
            'columns' => [
                ['blocks' => [['data' => []]]],
            ],
        ]);

        self::assertNotEmpty($errors);
        self::assertContains(
            'columns[0].blocks[0].blockType is required and must be a string',
            $errors,
        );
    }

    #[Test]
    public function validatesBlockDataMustBeArray(): void
    {
        $block = $this->createColumnsBlock();

        $errors = $block->validate([
            'columns' => [
                ['blocks' => [['blockType' => 'paragraph', 'data' => 'not-array']]],
            ],
        ]);

        self::assertContains(
            'columns[0].blocks[0].data is required and must be an object',
            $errors,
        );
    }

    #[Test]
    public function validatesBlockEntryMustBeObject(): void
    {
        $block = $this->createColumnsBlock();

        $errors = $block->validate([
            'columns' => [
                ['blocks' => ['not-an-object']],
            ],
        ]);

        self::assertContains(
            'columns[0].blocks[0] must be an object',
            $errors,
        );
    }

    #[Test]
    public function validDataReturnsNoErrors(): void
    {
        $block = $this->createColumnsBlock();

        $errors = $block->validate([
            'columns' => [
                ['blocks' => [['blockType' => 'paragraph', 'data' => ['text' => 'Hello']]]],
                ['blocks' => [['blockType' => 'heading', 'data' => ['text' => 'Title', 'level' => 2]]]],
            ],
        ]);

        self::assertSame([], $errors);
    }
}
