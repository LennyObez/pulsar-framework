<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\BlockEditor\CoreBlocks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\SearchBlock;

#[CoversClass(SearchBlock::class)]
final class SearchBlockTest extends TestCase
{
    private SearchBlock $block;

    protected function setUp(): void
    {
        $this->block = new SearchBlock();
    }

    public function testType(): void
    {
        self::assertSame('search', $this->block->type());
    }

    public function testSchemaReturnsValidStructure(): void
    {
        $schema = $this->block->schema();
        self::assertSame('object', $schema['type']);
        /** @var array<string, mixed> $properties */
        $properties = $schema['properties'];
        self::assertArrayHasKey('placeholder', $properties);
        self::assertArrayHasKey('action', $properties);
        self::assertArrayHasKey('buttonText', $properties);
    }

    public function testRenderWithDefaults(): void
    {
        $html = $this->block->render([]);

        self::assertStringContainsString('role="search"', $html);
        self::assertStringContainsString('action="/search"', $html);
        self::assertStringContainsString('placeholder="Search…"', $html);
        self::assertStringContainsString('type="search"', $html);
        self::assertStringContainsString('>Search</button>', $html);
    }

    public function testRenderWithCustomValues(): void
    {
        $html = $this->block->render([
            'placeholder' => 'Find articles...',
            'action' => '/find',
            'buttonText' => 'Go',
        ]);

        self::assertStringContainsString('placeholder="Find articles..."', $html);
        self::assertStringContainsString('action="/find"', $html);
        self::assertStringContainsString('>Go</button>', $html);
    }

    public function testRenderEscapesXss(): void
    {
        $html = $this->block->render([
            'placeholder' => '"><script>alert(1)</script>',
            'action' => '"><script>alert(1)</script>',
        ]);

        self::assertStringNotContainsString('<script>', $html);
    }

    public function testValidateWithValidData(): void
    {
        $errors = $this->block->validate([]);
        self::assertSame([], $errors);
    }

    public function testValidateRejectsInvalidTypes(): void
    {
        $errors = $this->block->validate([
            'placeholder' => 123,
            'action' => false,
            'buttonText' => [],
        ]);

        self::assertCount(3, $errors);
    }
}
