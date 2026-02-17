<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\BlockEditor\CoreBlocks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\TagCloudBlock;

#[CoversClass(TagCloudBlock::class)]
final class TagCloudBlockTest extends TestCase
{
    private TagCloudBlock $block;

    protected function setUp(): void
    {
        $this->block = new TagCloudBlock();
    }

    public function testType(): void
    {
        self::assertSame('tag-cloud', $this->block->type());
    }

    public function testSchemaRequiresTags(): void
    {
        $schema = $this->block->schema();
        /** @var list<string> $required */
        $required = $schema['required'];
        self::assertContains('tags', $required);
    }

    public function testRenderWithTags(): void
    {
        $html = $this->block->render([
            'tags' => [
                ['name' => 'PHP', 'url' => '/tags/php', 'count' => 10],
                ['name' => 'JS', 'url' => '/tags/js', 'count' => 5],
            ],
        ]);

        self::assertStringContainsString('class="tag-cloud"', $html);
        self::assertStringContainsString('href="/tags/php"', $html);
        self::assertStringContainsString('>PHP</a>', $html);
        self::assertStringContainsString('href="/tags/js"', $html);
    }

    public function testRenderAppliesWeightedFontSizes(): void
    {
        $html = $this->block->render([
            'tags' => [
                ['name' => 'Big', 'url' => '/big', 'count' => 100],
                ['name' => 'Small', 'url' => '/small', 'count' => 1],
            ],
            'minFontSize' => 10,
            'maxFontSize' => 40,
        ]);

        self::assertStringContainsString('font-size:40px', $html);
        self::assertStringContainsString('font-size:10px', $html);
    }

    public function testRenderEscapesXss(): void
    {
        $html = $this->block->render([
            'tags' => [
                ['name' => '<script>xss</script>', 'url' => '"><script>', 'count' => 1],
            ],
        ]);

        self::assertStringNotContainsString('<script>', $html);
    }

    public function testRenderSkipsNonArrayTags(): void
    {
        $html = $this->block->render([
            'tags' => ['not-an-array', null],
        ]);

        self::assertStringContainsString('class="tag-cloud"', $html);
    }

    public function testValidateRequiresTags(): void
    {
        $errors = $this->block->validate([]);
        self::assertContains('tags is required and must be an array', $errors);
    }

    public function testValidateRequiresTagFields(): void
    {
        $errors = $this->block->validate([
            'tags' => [
                ['name' => 'OK'],
            ],
        ]);

        self::assertNotEmpty($errors);
    }

    public function testValidateRejectsNegativeCount(): void
    {
        $errors = $this->block->validate([
            'tags' => [
                ['name' => 'Test', 'url' => '/test', 'count' => -1],
            ],
        ]);

        self::assertNotEmpty($errors);
    }

    public function testValidateAcceptsValidData(): void
    {
        $errors = $this->block->validate([
            'tags' => [
                ['name' => 'PHP', 'url' => '/tags/php', 'count' => 5],
            ],
        ]);

        self::assertSame([], $errors);
    }
}
