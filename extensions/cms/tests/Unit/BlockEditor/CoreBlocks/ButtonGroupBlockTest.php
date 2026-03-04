<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\BlockEditor\CoreBlocks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\ButtonGroupBlock;

#[CoversClass(ButtonGroupBlock::class)]
final class ButtonGroupBlockTest extends TestCase
{
    private ButtonGroupBlock $block;

    protected function setUp(): void
    {
        $this->block = new ButtonGroupBlock();
    }

    #[Test]
    public function typeReturnsButtonGroup(): void
    {
        self::assertSame('button-group', $this->block->type());
    }

    #[Test]
    public function renderOutputsButtonLinks(): void
    {
        $html = $this->block->render([
            'buttons' => [
                ['text' => 'Sign Up', 'url' => '/signup'],
                ['text' => 'Learn More', 'url' => '/about'],
            ],
        ]);

        self::assertStringContainsString('Sign Up</a>', $html);
        self::assertStringContainsString('href="/signup"', $html);
        self::assertStringContainsString('Learn More</a>', $html);
    }

    #[Test]
    #[DataProvider('alignmentProvider')]
    public function renderUsesValidAlignment(string $alignment): void
    {
        $html = $this->block->render([
            'buttons' => [['text' => 'Go', 'url' => '/']],
            'alignment' => $alignment,
        ]);

        self::assertStringContainsString("button-group--$alignment", $html);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function alignmentProvider(): array
    {
        return [
            'left' => ['left'],
            'center' => ['center'],
            'right' => ['right'],
        ];
    }

    #[Test]
    public function renderDefaultsToCenterAlignment(): void
    {
        $html = $this->block->render([
            'buttons' => [['text' => 'Go', 'url' => '/']],
            'alignment' => 'invalid',
        ]);

        self::assertStringContainsString('button-group--center', $html);
    }

    #[Test]
    public function renderDefaultsToHorizontalLayout(): void
    {
        $html = $this->block->render([
            'buttons' => [['text' => 'Go', 'url' => '/']],
        ]);

        self::assertStringContainsString('button-group--horizontal', $html);
    }

    #[Test]
    public function renderSupportsVerticalLayout(): void
    {
        $html = $this->block->render([
            'buttons' => [['text' => 'Go', 'url' => '/']],
            'layout' => 'vertical',
        ]);

        self::assertStringContainsString('button-group--vertical', $html);
    }

    #[Test]
    public function renderSkipsNonArrayButtons(): void
    {
        $html = $this->block->render([
            'buttons' => ['not-array', 42],
        ]);

        self::assertStringNotContainsString('button-group__button', $html);
    }

    #[Test]
    public function validateReturnsErrorWhenButtonsMissing(): void
    {
        $errors = $this->block->validate([]);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('buttons is required', $errors[0]);
    }

    #[Test]
    public function validateReturnsErrorForInvalidButtonEntry(): void
    {
        $errors = $this->block->validate([
            'buttons' => [['text' => 123, 'url' => null]],
        ]);

        self::assertNotEmpty($errors);
    }

    #[Test]
    public function validateReturnsErrorForInvalidAlignment(): void
    {
        $errors = $this->block->validate([
            'buttons' => [['text' => 'Go', 'url' => '/']],
            'alignment' => 'stretch',
        ]);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('alignment', $errors[0]);
    }

    #[Test]
    public function validateReturnsEmptyForValidData(): void
    {
        $errors = $this->block->validate([
            'buttons' => [['text' => 'Go', 'url' => '/']],
            'alignment' => 'left',
            'layout' => 'vertical',
        ]);

        self::assertSame([], $errors);
    }
}
