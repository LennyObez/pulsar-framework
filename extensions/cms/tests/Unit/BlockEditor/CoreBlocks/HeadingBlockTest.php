<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\BlockEditor\CoreBlocks;

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
    public function renderUsesCorrectHeadingLevel(int $level): void
    {
        $html = $this->block->render([
            'text' => 'Title',
            'level' => $level,
        ]);

        self::assertStringContainsString("<h$level", $html);
        self::assertStringContainsString("</h$level>", $html);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function levelProvider(): array
    {
        return [
            'h1' => [1],
            'h2' => [2],
            'h3' => [3],
            'h4' => [4],
            'h5' => [5],
            'h6' => [6],
        ];
    }

    #[Test]
    public function renderClampsInvalidLevelToH1(): void
    {
        $html = $this->block->render([
            'text' => 'Title',
            'level' => 99,
        ]);

        self::assertStringContainsString('<h1', $html);
    }

    #[Test]
    public function renderAutoGeneratesAnchorFromText(): void
    {
        $html = $this->block->render([
            'text' => 'Getting Started Guide',
            'level' => 2,
        ]);

        self::assertStringContainsString('id="getting-started-guide"', $html);
    }

    #[Test]
    public function renderUsesExplicitAnchor(): void
    {
        $html = $this->block->render([
            'text' => 'Introduction',
            'level' => 1,
            'anchor' => 'intro',
        ]);

        self::assertStringContainsString('id="intro"', $html);
    }

    #[Test]
    public function renderEscapesHtmlInText(): void
    {
        $html = $this->block->render([
            'text' => '<script>alert("xss")</script>',
            'level' => 2,
        ]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    public function renderSupportsClassName(): void
    {
        $html = $this->block->render([
            'text' => 'Title',
            'level' => 1,
            'className' => 'section-title',
        ]);

        self::assertStringContainsString('class="section-title"', $html);
    }

    #[Test]
    public function validateReturnsErrorForMissingText(): void
    {
        $errors = $this->block->validate(['level' => 2]);

        self::assertStringContainsString('text is required', $errors[0]);
    }

    #[Test]
    public function validateReturnsErrorForMissingLevel(): void
    {
        $errors = $this->block->validate(['text' => 'Title']);

        self::assertStringContainsString('level is required', $errors[0]);
    }

    #[Test]
    public function validateReturnsErrorForOutOfRangeLevel(): void
    {
        $errors = $this->block->validate([
            'text' => 'Title',
            'level' => 7,
        ]);

        self::assertStringContainsString('between 1 and 6', $errors[0]);
    }

    #[Test]
    public function validateReturnsEmptyForValidData(): void
    {
        self::assertSame([], $this->block->validate([
            'text' => 'Introduction',
            'level' => 2,
        ]));
    }
}
