<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Live;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Live\Internal\ActionDispatcher;
use Pulsar\Live\LiveAction;
use Pulsar\Live\LiveComponent;
use Pulsar\Live\LiveProp;

/**
 * Edge case tests for ActionDispatcher.
 */
#[CoversClass(ActionDispatcher::class)]
final class ActionDispatcherEdgeTest extends TestCase
{
    #[Test]
    public function dispatchReturnsFalseForUnknownAction(): void
    {
        $dispatcher = new ActionDispatcher();
        $component = new DispatchTestComponent();

        self::assertFalse($dispatcher->dispatch($component, 'nonexistent'));
    }

    #[Test]
    public function dispatchCallsActionMethod(): void
    {
        $dispatcher = new ActionDispatcher();
        $component = new DispatchTestComponent();

        $result = $dispatcher->dispatch($component, 'increment');

        self::assertTrue($result);
        self::assertSame(1, $component->count);
    }

    #[Test]
    public function dispatchPassesParametersToAction(): void
    {
        $dispatcher = new ActionDispatcher();
        $component = new DispatchTestComponent();

        $dispatcher->dispatch($component, 'add', ['amount' => 5]);

        self::assertSame(5, $component->count);
    }

    #[Test]
    public function dispatchUsesDefaultParameterValues(): void
    {
        $dispatcher = new ActionDispatcher();
        $component = new DispatchTestComponent();

        $dispatcher->dispatch($component, 'add', []);

        self::assertSame(1, $component->count);
    }

    #[Test]
    public function dispatchReturnsFalseWhenBeforeActionReturnsFalse(): void
    {
        $dispatcher = new ActionDispatcher();
        $component = new BeforeActionBlockingComponent();

        $result = $dispatcher->dispatch($component, 'doSomething');

        self::assertFalse($result);
        self::assertFalse($component->wasExecuted);
    }

    #[Test]
    public function dispatchCallsAfterAction(): void
    {
        $dispatcher = new ActionDispatcher();
        $component = new AfterActionTrackingComponent();

        $dispatcher->dispatch($component, 'doSomething');

        self::assertSame('doSomething', $component->lastActionAfter);
    }

    #[Test]
    public function getActionNamesReturnsRegisteredActions(): void
    {
        $dispatcher = new ActionDispatcher();
        $component = new DispatchTestComponent();

        $names = $dispatcher->getActionNames($component);

        self::assertContains('increment', $names);
        self::assertContains('add', $names);
    }

    #[Test]
    public function getActionNamesUsesCustomNameFromAttribute(): void
    {
        $dispatcher = new ActionDispatcher();
        $component = new CustomNameActionComponent();

        $names = $dispatcher->getActionNames($component);

        self::assertContains('custom-name', $names);
    }

    #[Test]
    public function hasActionReturnsTrueForExistingAction(): void
    {
        $dispatcher = new ActionDispatcher();
        $component = new DispatchTestComponent();

        self::assertTrue($dispatcher->hasAction($component, 'increment'));
        self::assertFalse($dispatcher->hasAction($component, 'nonexistent'));
    }

    #[Test]
    public function dispatchWithCustomActionName(): void
    {
        $dispatcher = new ActionDispatcher();
        $component = new CustomNameActionComponent();

        $result = $dispatcher->dispatch($component, 'custom-name');

        self::assertTrue($result);
        self::assertTrue($component->executed);
    }
}

/** @internal */
final class DispatchTestComponent extends LiveComponent
{
    #[LiveProp(writable: true)]
    public int $count = 0;

    #[LiveAction]
    public function increment(): void
    {
        $this->count++;
    }

    #[LiveAction]
    public function add(int $amount = 1): void
    {
        $this->count += $amount;
    }

    public function render(): string
    {
        return '<div>' . $this->count . '</div>';
    }
}

/** @internal */
final class BeforeActionBlockingComponent extends LiveComponent
{
    public bool $wasExecuted = false;

    public function beforeAction(string $action): bool
    {
        return false;
    }

    #[LiveAction]
    public function doSomething(): void
    {
        $this->wasExecuted = true;
    }

    public function render(): string
    {
        return '';
    }
}

/** @internal */
final class AfterActionTrackingComponent extends LiveComponent
{
    public string $lastActionAfter = '';

    public function afterAction(string $action): void
    {
        $this->lastActionAfter = $action;
    }

    #[LiveAction]
    public function doSomething(): void {}

    public function render(): string
    {
        return '';
    }
}

/** @internal */
final class CustomNameActionComponent extends LiveComponent
{
    public bool $executed = false;

    #[LiveAction(name: 'custom-name')]
    public function myMethod(): void
    {
        $this->executed = true;
    }

    public function render(): string
    {
        return '';
    }
}
