<?php

declare(strict_types=1);

namespace Tests\Unit\Extension\Cms\BlockEditor\CoreBlocks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\HeadingBlock;

#[CoversClass(HeadingBlock::class)]
final class HeadingBlockTest extends TestCase
{
    private HeadingBlock $block;

    protected function setUp(): void
    {
        $this->block = new HeadingBlock();
    }

    #[Test]
    public function typeReturnsHeading(): void
    {
        self::assertSame('heading', $this->block->type());
    }

    #[Test]
    #[DataProvider('levelProvider')]
    public function rendersCorrectHeadingLevel(int $level): void
    {
        $html = $this->block->render(['text' => 'Title', 'level' => $level]);

        self::assertSame("<h{$level}>Title</h{$level}>", $html);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function levelProvider(): iterable
    {
        yield 'h1' => [1];
        yield 'h2' => [2];
        yield 'h3' => [3];
        yield 'h4' => [4];
        yield 'h5' => [5];
        yield 'h6' => [6];
    }

    #[Test]
    public function invalidLevelDefaultsToH1(): void
    {
        $html = $this->block->render(['text' => 'Title', 'level' => 0]);

        self::assertSame('<h1>Title</h1>', $html);
    }

    #[Test]
    public function escapesXssInText(): void
    {
        $html = $this->block->render(['text' => '<img src=x onerror=alert(1)>', 'level' => 2]);

        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString('&lt;img', $html);
    }

    #[Test]
    public function validatesRequiredText(): void
    {
        $errors = $this->block->validate(['level' => 1]);

        self::assertContains('text is required and must be a string', $errors);
    }

    #[Test]
    public function validatesRequiredLevel(): void
    {
        $errors = $this->block->validate(['text' => 'Title']);

        self::assertContains('level is required and must be an integer', $errors);
    }

    #[Test]
    public function validatesLevelRange(): void
    {
        $errors = $this->block->validate(['text' => 'Title', 'level' => 7]);

        self::assertContains('level must be between 1 and 6', $errors);
    }

    #[Test]
    public function validatesLevelIsInteger(): void
    {
        $errors = $this->block->validate(['text' => 'Title', 'level' => 'two']);

        self::assertContains('level is required and must be an integer', $errors);
    }

    #[Test]
    public function validDataReturnsNoErrors(): void
    {
        $errors = $this->block->validate(['text' => 'Title', 'level' => 3]);

        self::assertSame([], $errors);
    }
}
