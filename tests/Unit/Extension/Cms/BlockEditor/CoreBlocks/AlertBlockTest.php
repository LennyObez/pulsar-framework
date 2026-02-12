<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\BlockEditor\CoreBlocks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\AlertBlock;

#[CoversClass(AlertBlock::class)]
final class AlertBlockTest extends TestCase
{
    private AlertBlock $block;

    protected function setUp(): void
    {
        $this->block = new AlertBlock();
    }

    #[Test]
    public function typeReturnsAlert(): void
    {
        self::assertSame('alert', $this->block->type());
    }

    #[Test]
    public function rendersWithRequiredFieldsOnly(): void
    {
        $html = $this->block->render([
            'message' => 'System updated.',
            'alertType' => 'info',
        ]);

        self::assertSame(
            '<div class="alert alert--info" role="alert">System updated.</div>',
            $html,
        );
    }

    #[Test]
    public function rendersWithAllOptionalFields(): void
    {
        $html = $this->block->render([
            'message' => 'Saved!',
            'alertType' => 'success',
            'dismissible' => true,
        ]);

        self::assertStringContainsString('alert--success', $html);
        self::assertStringContainsString('data-dismissible="true"', $html);
        self::assertStringContainsString('Saved!', $html);
    }

    #[Test]
    public function escapesXssInMessage(): void
    {
        $html = $this->block->render([
            'message' => '<script>alert("xss")</script>',
            'alertType' => 'warning',
        ]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    public function validatesRequiredMessage(): void
    {
        $errors = $this->block->validate(['alertType' => 'info']);

        self::assertContains('message is required and must be a string', $errors);
    }

    #[Test]
    public function validatesRequiredAlertType(): void
    {
        $errors = $this->block->validate(['message' => 'Hello']);

        self::assertContains('alertType is required and must be one of: info, warning, error, success', $errors);
    }

    #[Test]
    public function validatesInvalidAlertType(): void
    {
        $errors = $this->block->validate([
            'message' => 'Hello',
            'alertType' => 'critical',
        ]);

        self::assertContains('alertType is required and must be one of: info, warning, error, success', $errors);
    }

    #[Test]
    public function validDataReturnsNoErrors(): void
    {
        $errors = $this->block->validate([
            'message' => 'All good',
            'alertType' => 'success',
        ]);

        self::assertSame([], $errors);
    }
}
