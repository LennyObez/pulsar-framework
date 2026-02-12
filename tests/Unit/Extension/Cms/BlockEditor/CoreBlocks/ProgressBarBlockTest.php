<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\BlockEditor\CoreBlocks;

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
    public function rendersWithRequiredFieldsOnly(): void
    {
        $html = $this->block->render([
            'value' => 75,
            'label' => 'Progress',
        ]);

        self::assertStringContainsString('class="progress"', $html);
        self::assertStringContainsString('<div class="progress__label">Progress</div>', $html);
        self::assertStringContainsString('aria-valuenow="75"', $html);
        self::assertStringContainsString('style="width:75%"', $html);
        self::assertStringContainsString('<span class="progress__percentage">75%</span>', $html);
    }

    #[Test]
    public function rendersWithAllOptionalFields(): void
    {
        $html = $this->block->render([
            'value' => 50,
            'label' => 'Upload',
            'color' => '#ff0000',
            'showPercentage' => true,
        ]);

        self::assertStringContainsString('background-color:#ff0000', $html);
        self::assertStringContainsString('<span class="progress__percentage">50%</span>', $html);
    }

    #[Test]
    public function hidesPercentageWhenDisabled(): void
    {
        $html = $this->block->render([
            'value' => 30,
            'label' => 'Loading',
            'showPercentage' => false,
        ]);

        self::assertStringNotContainsString('progress__percentage', $html);
    }

    #[Test]
    public function escapesXssInLabel(): void
    {
        $html = $this->block->render([
            'value' => 10,
            'label' => '<script>xss</script>',
        ]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    public function escapesXssInColor(): void
    {
        $html = $this->block->render([
            'value' => 10,
            'label' => 'Test',
            'color' => '"><script>xss</script>',
        ]);

        self::assertStringNotContainsString('<script>', $html);
    }

    #[Test]
    public function validatesRequiredValue(): void
    {
        $errors = $this->block->validate(['label' => 'Test']);

        self::assertContains('value is required and must be an integer', $errors);
    }

    #[Test]
    public function validatesRequiredLabel(): void
    {
        $errors = $this->block->validate(['value' => 50]);

        self::assertContains('label is required and must be a string', $errors);
    }

    #[Test]
    public function validatesValueAboveMax(): void
    {
        $errors = $this->block->validate([
            'value' => 101,
            'label' => 'Test',
        ]);

        self::assertContains('value must be between 0 and 100', $errors);
    }

    #[Test]
    public function validatesValueBelowMin(): void
    {
        $errors = $this->block->validate([
            'value' => -1,
            'label' => 'Test',
        ]);

        self::assertContains('value must be between 0 and 100', $errors);
    }

    #[Test]
    public function validDataReturnsNoErrors(): void
    {
        $errors = $this->block->validate([
            'value' => 0,
            'label' => 'Progress',
        ]);

        self::assertSame([], $errors);
    }
}
