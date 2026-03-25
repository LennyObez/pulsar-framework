<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Plugins;

use Closure;
use Pulsar\Api\Api;

use function array_key_exists;
use function usort;

/**
 * Stores registered hooks by hook point and priority.
 *
 * Plugins register callbacks for named hook points with a priority.
 * Lower priority values execute first.
 *
 * @psalm-api Public registry resolved from the DI container by CmsPluginManager
 *            and HookExecutionEngine; not instantiated by name.
 */
#[Api(since: '1.0.0')]
final class HookRegistry
{
    /** @var array<string, list<array{callback: Closure, priority: int, pluginSlug: string}>> */
    private array $hooks = [];

    /**
     * Register a hook callback for a given hook point.
     */
    public function register(string $hookPoint, Closure $callback, int $priority, string $pluginSlug): void
    {
        $this->hooks[$hookPoint][] = [
            'callback' => $callback,
            'priority' => $priority,
            'pluginSlug' => $pluginSlug,
        ];
    }

    /**
     * Get all registered callbacks for a hook point, sorted by priority (ascending).
     *
     * @return list<array{callback: Closure, priority: int, pluginSlug: string}>
     */
    public function getCallbacks(string $hookPoint): array
    {
        if (!array_key_exists($hookPoint, $this->hooks)) {
            return [];
        }

        $callbacks = $this->hooks[$hookPoint];

        usort($callbacks, static fn(array $a, array $b): int => $a['priority'] <=> $b['priority']);

        return $callbacks;
    }

    /**
     * Get all registered hook points.
     *
     * @return list<string>
     */
    public function getHookPoints(): array
    {
        return array_keys($this->hooks);
    }

    /**
     * Check if a hook point has any registered callbacks.
     */
    public function has(string $hookPoint): bool
    {
        return array_key_exists($hookPoint, $this->hooks) && $this->hooks[$hookPoint] !== [];
    }
}
