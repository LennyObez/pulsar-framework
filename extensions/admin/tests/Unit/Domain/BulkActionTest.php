<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Domain\BulkAction;

#[CoversClass(BulkAction::class)]
final class BulkActionTest extends TestCase
{
    #[Test]
    public function constructorSetsRequiredProperties(): void
    {
        $action = new BulkAction(
            name: 'archive',
            label: 'Archive selected',
        );

        self::assertSame('archive', $action->name);
        self::assertSame('Archive selected', $action->label);
        self::assertFalse($action->destructive);
        self::assertTrue($action->requireConfirmation);
        self::assertNull($action->icon);
    }

    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $action = new BulkAction(
            name: 'delete',
            label: 'Delete selected',
            destructive: true,
            requireConfirmation: true,
            icon: 'trash',
        );

        self::assertSame('delete', $action->name);
        self::assertSame('Delete selected', $action->label);
        self::assertTrue($action->destructive);
        self::assertTrue($action->requireConfirmation);
        self::assertSame('trash', $action->icon);
    }

    #[Test]
    public function nonDestructiveActionWithoutConfirmation(): void
    {
        $action = new BulkAction(
            name: 'tag',
            label: 'Tag items',
            destructive: false,
            requireConfirmation: false,
            icon: 'tag',
        );

        self::assertFalse($action->destructive);
        self::assertFalse($action->requireConfirmation);
    }
}
