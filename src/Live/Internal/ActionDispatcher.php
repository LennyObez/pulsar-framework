<?php

declare(strict_types=1);

namespace Pulsar\Live\Internal;

use Pulsar\Api\Internal;
use Pulsar\Live\LiveAction;
use Pulsar\Live\LiveComponent;
use ReflectionClass;
use ReflectionMethod;

use function array_keys;

/**
 * Dispatches frontend actions to component methods.
 *
 * Validates that the target method is marked with #[LiveAction]
 * before invocation, preventing arbitrary method calls.
 *
 * Per-class action metadata (action name => ReflectionMethod) is
 * cached in a static lookup. Component class structure is immutable
 * at runtime so the cache survives the process lifetime, eliminating
 * the per-dispatch `new ReflectionClass()` + attribute-scan overhead
 * flagged by the M-2 audit finding.
 */
#[Internal]
final class ActionDispatcher
{
    /**
     * Per-class action name map: `componentClass => [actionName => ReflectionMethod]`.
     *
     * @var array<class-string<LiveComponent>, array<string, ReflectionMethod>>
     */
    private static array $actionCache = [];

    /**
     * Execute an action on a component.
     *
     * @param array<string, mixed> $params Action parameters
     * @return bool Whether the action was executed
     */
    public function dispatch(LiveComponent $component, string $actionName, array $params = []): bool
    {
        $method = $this->resolveMethod($component, $actionName);

        if ($method === null) {
            return false;
        }

        if (!$component->beforeAction($actionName)) {
            return false;
        }

        $method->invoke($component, ...$this->resolveParameters($method, $params));
        $component->afterAction($actionName);

        return true;
    }

    /**
     * Get all registered action names for a component.
     *
     * @return list<string>
     */
    public function getActionNames(LiveComponent $component): array
    {
        return array_keys(self::describeActions($component));
    }

    /**
     * Whether an action exists on the component.
     */
    public function hasAction(LiveComponent $component, string $actionName): bool
    {
        return isset(self::describeActions($component)[$actionName]);
    }

    private function resolveMethod(LiveComponent $component, string $actionName): ?ReflectionMethod
    {
        return self::describeActions($component)[$actionName] ?? null;
    }

    /**
     * Resolve (and cache) the action map for a component class.
     *
     * @return array<string, ReflectionMethod>
     */
    private static function describeActions(LiveComponent $component): array
    {
        $class = $component::class;

        if (isset(self::$actionCache[$class])) {
            return self::$actionCache[$class];
        }

        $reflection = new ReflectionClass($class);
        $actions = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $attributes = $method->getAttributes(LiveAction::class);

            if ($attributes === []) {
                continue;
            }

            /** @var LiveAction $liveAction */
            $liveAction = $attributes[0]->newInstance();
            $name = $liveAction->name !== '' ? $liveAction->name : $method->getName();
            $actions[$name] = $method;
        }

        return self::$actionCache[$class] = $actions;
    }

    /**
     * @param array<string, mixed> $params
     * @return list<mixed>
     */
    private function resolveParameters(ReflectionMethod $method, array $params): array
    {
        $resolved = [];

        foreach ($method->getParameters() as $parameter) {
            $name = $parameter->getName();

            if (isset($params[$name])) {
                $resolved[] = $params[$name];
            } elseif ($parameter->isDefaultValueAvailable()) {
                $resolved[] = $parameter->getDefaultValue();
            } else {
                $resolved[] = null;
            }
        }

        return $resolved;
    }
}
