<?php

declare(strict_types=1);

namespace Pulsar\Live;

use Pulsar\Api\Api;

/**
 * Base class for server-driven reactive UI components.
 *
 * Subclasses declare #[LiveProp] properties for state tracking
 * and #[LiveAction] methods for frontend-triggered actions.
 *
 * Example:
 *   final class Counter extends LiveComponent {
 *       #[LiveProp(writable: true)]
 *       public int $count = 0;
 *
 *       #[LiveAction]
 *       public function increment(): void { $this->count++; }
 *
 *       public function render(): string {
 *           return '<div>Count: ' . $this->count . '</div>';
 *       }
 *   }
 */
#[Api(since: '1.0.0')]
abstract class LiveComponent
{
    /**
     * Render the component's HTML.
     *
     * Called after every action or property update. The returned HTML
     * is sent to the frontend for DOM morphing.
     */
    abstract public function render(): string;

    /**
     * Called once when the component is first mounted.
     *
     * Use this for initialization logic (loading data, setting defaults).
     * Called before the first render.
     *
     * @param array<string, mixed> $params Mount parameters
     */
    public function mount(array $params = []): void
    {
        // Override in subclass
    }

    /**
     * Called before each action is executed.
     *
     * Return false to prevent the action from running.
     */
    public function beforeAction(string $action): bool
    {
        return true;
    }

    /**
     * Called after each action is executed.
     */
    public function afterAction(string $action): void
    {
        // Override in subclass
    }

    /**
     * Get the component name (used for routing and registration).
     *
     * Defaults to the short class name in kebab-case.
     */
    public function getName(): string
    {
        $class = static::class;
        $shortName = substr($class, strrpos($class, '\\') + 1);

        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $shortName) ?? $shortName);
    }
}
