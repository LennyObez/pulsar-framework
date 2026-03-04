<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\BlockEditor\CoreBlocks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
    public function schemaRequiresMessageAndAlertType(): void
    {
        $schema = $this->block->schema();

        self::assertSame(['message', 'alertType'], $schema['required']);
    }

    #[Test]
    #[DataProvider('alertTypeProvider')]
    public function renderOutputsCorrectAlertType(string $alertType, string $expectedRole): void
    {
        $html = $this->block->render([
            'message' => 'Test message',
            'alertType' => $alertType,
        ]);

        self::assertStringContainsString("alert--$alertType", $html);
        self::assertStringContainsString("role=\"$expectedRole\"", $html);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function alertTypeProvider(): array
    {
        return [
            'info uses status role' => ['info', 'status'],
            'warning uses alert role' => ['warning', 'alert'],
            'error uses alert role' => ['error', 'alert'],
            'success uses alert role' => ['success', 'alert'],
        ];
    }

    #[Test]
    public function renderFallsBackToInfoForInvalidAlertType(): void
    {
        $html = $this->block->render([
            'message' => 'Test',
            'alertType' => 'critical',
        ]);

        self::assertStringContainsString('alert--info', $html);
    }

    #[Test]
    public function renderSetsDismissibleAttribute(): void
    {
        $html = $this->block->render([
            'message' => 'Dismissible alert',
            'alertType' => 'info',
            'dismissible' => true,
        ]);

        self::assertStringContainsString('data-dismissible="true"', $html);
    }

    #[Test]
    public function renderEscapesHtmlInMessage(): void
    {
        $html = $this->block->render([
            'message' => '<img src=x onerror=alert(1)>',
            'alertType' => 'error',
        ]);

        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString('&lt;img', $html);
    }

    #[Test]
    public function renderSupportsAnchorAndClassName(): void
    {
        $html = $this->block->render([
            'message' => 'Test',
            'alertType' => 'info',
            'anchor' => 'my-alert',
            'className' => 'custom',
        ]);

        self::assertStringContainsString('id="my-alert"', $html);
        self::assertStringContainsString('custom', $html);
    }

    #[Test]
    public function validateReturnsErrorForMissingMessage(): void
    {
        $errors = $this->block->validate(['alertType' => 'info']);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('message is required', $errors[0]);
    }

    #[Test]
    public function validateReturnsErrorForInvalidAlertType(): void
    {
        $errors = $this->block->validate([
            'message' => 'Test',
            'alertType' => 'critical',
        ]);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('alertType', $errors[0]);
    }

    #[Test]
    public function validateReturnsEmptyForValidData(): void
    {
        $errors = $this->block->validate([
            'message' => 'All good',
            'alertType' => 'success',
        ]);

        self::assertSame([], $errors);
    }
}
