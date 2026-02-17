<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Live;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Live\LiveAction;
use Pulsar\Live\LiveComponent;
use Pulsar\Live\LiveProp;

/**
 * Edge case tests for LiveComponent base class.
 */
#[CoversClass(LiveComponent::class)]
final class LiveComponentEdgeTest extends TestCase
{
    #[Test]
    public function getNameReturnsKebabCaseOfClassName(): void
    {
        $component = new TestCounterComponent();

        self::assertSame('test-counter-component', $component->getName());
    }

    #[Test]
    public function mountDefaultsToNoOp(): void
    {
        $component = new TestCounterComponent();
        $component->mount(['start' => 5]);

        // Default mount does nothing -- counter stays at default
        self::assertSame(0, $component->count);
    }

    #[Test]
    public function beforeActionDefaultReturnsTrue(): void
    {
        $component = new TestCounterComponent();

        self::assertTrue($component->beforeAction('anything'));
    }

    #[Test]
    public function afterActionDefaultIsNoOp(): void
    {
        $component = new TestCounterComponent();
        $component->afterAction('anything');

        // No exception, no side effect
        self::assertSame(0, $component->count);
    }

    #[Test]
    public function renderReturnsHtml(): void
    {
        $component = new TestCounterComponent();
        $component->count = 42;

        self::assertSame('<div>Count: 42</div>', $component->render());
    }
}

/** @internal */
final class TestCounterComponent extends LiveComponent
{
    #[LiveProp(writable: true)]
    public int $count = 0;

    #[LiveAction]
    public function increment(): void
    {
        $this->count++;
    }

    public function render(): string
    {
        return '<div>Count: ' . $this->count . '</div>';
    }
}
