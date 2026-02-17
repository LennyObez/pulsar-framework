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

#[CoversClass(ActionDispatcher::class)]
final class ActionDispatcherTest extends TestCase
{
    private ActionDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->dispatcher = new ActionDispatcher();
    }

    #[Test]
    public function dispatchCallsAction(): void
    {
        $component = new ActionTestComponent();

        $result = $this->dispatcher->dispatch($component, 'increment');

        self::assertTrue($result);
        self::assertSame(1, $component->count);
    }

    #[Test]
    public function dispatchWithParams(): void
    {
        $component = new ActionTestComponent();

        $result = $this->dispatcher->dispatch($component, 'addAmount', ['amount' => 5]);

        self::assertTrue($result);
        self::assertSame(5, $component->count);
    }

    #[Test]
    public function dispatchReturnsFalseForUnknownAction(): void
    {
        $component = new ActionTestComponent();

        $result = $this->dispatcher->dispatch($component, 'nonexistent');

        self::assertFalse($result);
    }

    #[Test]
    public function dispatchReturnsFalseForNonAnnotatedMethod(): void
    {
        $component = new ActionTestComponent();

        $result = $this->dispatcher->dispatch($component, 'render');

        self::assertFalse($result);
    }

    #[Test]
    public function dispatchRespectsBeforeAction(): void
    {
        $component = new GuardedActionComponent();

        $result = $this->dispatcher->dispatch($component, 'blocked');

        self::assertFalse($result);
        self::assertFalse($component->wasExecuted);
    }

    #[Test]
    public function dispatchCallsAfterAction(): void
    {
        $component = new ActionTestComponent();
        $this->dispatcher->dispatch($component, 'increment');

        self::assertSame('increment', $component->lastAction);
    }

    #[Test]
    public function getActionNames(): void
    {
        $component = new ActionTestComponent();

        $names = $this->dispatcher->getActionNames($component);

        self::assertContains('increment', $names);
        self::assertContains('addAmount', $names);
        self::assertContains('custom-name', $names);
    }

    #[Test]
    public function hasAction(): void
    {
        $component = new ActionTestComponent();

        self::assertTrue($this->dispatcher->hasAction($component, 'increment'));
        self::assertTrue($this->dispatcher->hasAction($component, 'custom-name'));
        self::assertFalse($this->dispatcher->hasAction($component, 'render'));
        self::assertFalse($this->dispatcher->hasAction($component, 'nonexistent'));
    }

    #[Test]
    public function customActionName(): void
    {
        $component = new ActionTestComponent();

        $result = $this->dispatcher->dispatch($component, 'custom-name');

        self::assertTrue($result);
        self::assertTrue($component->resetCalled);
    }
}

/** @internal */
final class ActionTestComponent extends LiveComponent
{
    #[LiveProp(writable: true)]
    public int $count = 0;

    public string $lastAction = '';
    public bool $resetCalled = false;

    #[LiveAction]
    public function increment(): void
    {
        $this->count++;
    }

    #[LiveAction]
    public function addAmount(int $amount = 1): void
    {
        $this->count += $amount;
    }

    #[LiveAction(name: 'custom-name')]
    public function reset(): void
    {
        $this->resetCalled = true;
    }

    public function afterAction(string $action): void
    {
        $this->lastAction = $action;
    }

    public function render(): string
    {
        return '<div>' . $this->count . '</div>';
    }
}

/** @internal */
final class GuardedActionComponent extends LiveComponent
{
    public bool $wasExecuted = false;

    public function beforeAction(string $action): bool
    {
        return $action !== 'blocked';
    }

    #[LiveAction]
    public function blocked(): void
    {
        $this->wasExecuted = true;
    }

    public function render(): string
    {
        return '<div>guarded</div>';
    }
}
