<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Live;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Live\LiveComponent;

#[CoversClass(LiveComponent::class)]
final class LiveComponentTest extends TestCase
{
    #[Test]
    public function getNameConvertsToKebabCase(): void
    {
        $component = new class extends LiveComponent {
            public function render(): string
            {
                return '';
            }
        };

        // Anonymous class — test the algorithm with a named class
        $named = new UserProfileComponent();

        self::assertSame('user-profile-component', $named->getName());
    }

    #[Test]
    public function mountDoesNothingByDefault(): void
    {
        $component = new SimpleTestComponent();
        $component->mount(['key' => 'value']);

        // Default mount is a no-op — should not throw
        self::assertSame('<div>simple</div>', $component->render());
    }

    #[Test]
    public function beforeActionReturnsTrueByDefault(): void
    {
        $component = new SimpleTestComponent();

        self::assertTrue($component->beforeAction('anything'));
    }

    #[Test]
    public function afterActionDoesNothingByDefault(): void
    {
        $component = new SimpleTestComponent();
        $component->afterAction('anything');

        // No-op — should not throw
        self::addToAssertionCount(1);
    }

    #[Test]
    public function mountCanBeOverridden(): void
    {
        $component = new MountableComponent();
        $component->mount(['greeting' => 'Hello']);

        self::assertSame('Hello', $component->greeting);
    }
}

/** @internal */
final class SimpleTestComponent extends LiveComponent
{
    public function render(): string
    {
        return '<div>simple</div>';
    }
}

/** @internal */
final class UserProfileComponent extends LiveComponent
{
    public function render(): string
    {
        return '<div>profile</div>';
    }
}

/** @internal */
final class MountableComponent extends LiveComponent
{
    public string $greeting = '';

    /** @param array<string, string> $params */
    public function mount(array $params = []): void
    {
        $this->greeting = $params['greeting'] ?? '';
    }

    public function render(): string
    {
        return '<div>' . $this->greeting . '</div>';
    }
}
