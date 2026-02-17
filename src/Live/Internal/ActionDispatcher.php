<?php

declare(strict_types=1);

namespace Pulsar\Live\Internal;

use Pulsar\Api\Internal;
use Pulsar\Live\LiveAction;
use Pulsar\Live\LiveComponent;
use ReflectionClass;
use ReflectionMethod;

/**
 * Dispatches frontend actions to component methods.
 *
 * Validates that the target method is marked with #[LiveAction]
 * before invocation, preventing arbitrary method calls.
 */
#[Internal]
final readonly class ActionDispatcher
{
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
        $names = [];
        $reflection = new ReflectionClass($component);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $attributes = $method->getAttributes(LiveAction::class);

            if ($attributes === []) {
                continue;
            }

            /** @var LiveAction $liveAction */
            $liveAction = $attributes[0]->newInstance();
            $name = $liveAction->name !== '' ? $liveAction->name : $method->getName();
            $names[] = $name;
        }

        return $names;
    }

    /**
     * Whether an action exists on the component.
     */
    public function hasAction(LiveComponent $component, string $actionName): bool
    {
        return $this->resolveMethod($component, $actionName) !== null;
    }

    private function resolveMethod(LiveComponent $component, string $actionName): ?ReflectionMethod
    {
        $reflection = new ReflectionClass($component);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $attributes = $method->getAttributes(LiveAction::class);

            if ($attributes === []) {
                continue;
            }

            /** @var LiveAction $liveAction */
            $liveAction = $attributes[0]->newInstance();
            $name = $liveAction->name !== '' ? $liveAction->name : $method->getName();

            if ($name === $actionName) {
                return $method;
            }
        }

        return null;
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
