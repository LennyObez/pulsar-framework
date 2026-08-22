<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\BlockEditor\CoreBlocks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\ProgressBarBlock;

#[CoversClass(ProgressBarBlock::class)]
final class ProgressBarBlockTest extends TestCase
{
    private ProgressBarBlock $block;

    protected function setUp(): void
    {
        $this->block = new ProgressBarBlock();
    }

    #[Test]
    public function typeReturnsProgressBar(): void
    {
        self::assertSame('progress-bar', $this->block->type());
    }

    #[Test]
    public function renderOutputsProgressBarWithAriaAttributes(): void
    {
        $html = $this->block->render([
            'value' => 75,
            'label' => 'Completion',
        ]);

        self::assertStringContainsString('role="progressbar"', $html);
        self::assertStringContainsString('aria-valuenow="75"', $html);
        self::assertStringContainsString('aria-valuemin="0"', $html);
        self::assertStringContainsString('aria-valuemax="100"', $html);
        self::assertStringContainsString('width:75%', $html);
        self::assertStringContainsString('Completion', $html);
    }

    #[Test]
    public function renderShowsPercentageByDefault(): void
    {
        $html = $this->block->render([
            'value' => 50,
            'label' => 'Progress',
        ]);

        self::assertStringContainsString('progress__percentage', $html);
        self::assertStringContainsString('50%', $html);
    }

    #[Test]
    public function renderHidesPercentageWhenDisabled(): void
    {
        $html = $this->block->render([
            'value' => 50,
            'label' => 'Progress',
            'showPercentage' => false,
        ]);

        self::assertStringNotContainsString('progress__percentage', $html);
    }

    #[Test]
    public function renderAppliesCustomColor(): void
    {
        $html = $this->block->render([
            'value' => 80,
            'label' => 'Tasks',
            'color' => '#00ff00',
        ]);

        self::assertStringContainsString('background-color:#00ff00', $html);
    }

    #[Test]
    public function validateReturnsErrorForMissingValue(): void
    {
        $errors = $this->block->validate(['label' => 'X']);

        self::assertStringContainsString('value is required', $errors[0]);
    }

    #[Test]
    public function validateReturnsErrorForOutOfRangeValue(): void
    {
        $errors = $this->block->validate([
            'value' => 101,
            'label' => 'X',
        ]);

        self::assertStringContainsString('between 0 and 100', $errors[0]);
    }

    #[Test]
    public function validateReturnsErrorForMissingLabel(): void
    {
        $errors = $this->block->validate(['value' => 50]);

        self::assertStringContainsString('label is required', $errors[0]);
    }

    #[Test]
    public function validateReturnsEmptyForValidData(): void
    {
        self::assertSame([], $this->block->validate([
            'value' => 100,
            'label' => 'Done',
        ]));
    }
}
