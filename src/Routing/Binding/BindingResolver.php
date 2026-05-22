<?php

declare(strict_types=1);

namespace Pulsar\Routing\Binding;

use Pulsar\Api\Internal;
use Pulsar\Routing\MatchedRoute;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;

use function array_key_exists;
use function class_exists;
use function strlen;
use function strpos;
use function substr;

/**
 * Resolves binding metadata for route parameters.
 *
 * Uses either a pre-compiled binding map (production) or runtime
 * reflection on controller type hints (development) to determine
 * which route parameters should be resolved as domain models.
 */
#[Internal(reason: 'Implementation detail of the model binding pipeline')]
final readonly class BindingResolver
{
    /**
     * @param list<ExplicitBinding> $explicitBindings
     */
    public function __construct(
        private array $explicitBindings = [],
        private ?CompiledBindingMap $compiledMap = null,
    ) {}

    /**
     * Resolve binding metadata for all parameters of a matched route.
     *
     * @param class-string $controllerClass
     *
     * @return array<string, BindingMeta>
     */
    public function resolveForRoute(
        MatchedRoute $matchedRoute,
        string $controllerClass,
        string $controllerMethod,
    ): array {
        $routeName = $matchedRoute->getName();

        // Fast path: use compiled map if available and route is named
        if ($this->compiledMap !== null && $routeName !== null) {
            $compiled = $this->compiledMap->getForRoute($routeName);
            if ($compiled !== []) {
                return $this->applyExplicitOverrides($compiled, $matchedRoute);
            }
        }

        // Slow path: reflection-based implicit resolution
        $routeParameters = $matchedRoute->parameters;
        $implicit = $this->resolveImplicit($controllerClass, $controllerMethod, $routeParameters);

        return $this->applyExplicitOverrides($implicit, $matchedRoute);
    }

    /**
     * Resolve implicit bindings via controller method reflection.
     *
     * Inspects type hints on the controller method parameters. For each
     * parameter whose name matches a route parameter and whose type hint
     * is a class (not a scalar or built-in), creates a BindingMeta.
     *
     * @param class-string $controllerClass
     * @param array<string, string> $routeParameters
     *
     * @return array<string, BindingMeta>
     */
    public function resolveImplicit(
        string $controllerClass,
        string $controllerMethod,
        array $routeParameters,
    ): array {
        try {
            $reflection = new ReflectionMethod($controllerClass, $controllerMethod);
        } catch (ReflectionException) {
            return [];
        }

        $bindings = [];

        foreach ($reflection->getParameters() as $param) {
            $paramName = $param->getName();

            // Check if this parameter name matches a route parameter
            // Route parameters may use {param:key} syntax; strip the key suffix
            $routeParamName = $this->findRouteParameter($paramName, $routeParameters);
            if ($routeParamName === null) {
                continue;
            }

            $type = $param->getType();
            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            /** @var class-string $className */
            $className = $type->getName();
            if (!class_exists($className)) {
                continue;
            }

            $bindings[$paramName] = new BindingMeta(
                class: $className,
                keyName: 'id',
                keyType: 'string',
            );
        }

        return $bindings;
    }

    /**
     * Find the route parameter that corresponds to a method parameter name.
     *
     * @param array<string, string> $routeParameters
     */
    private function findRouteParameter(string $paramName, array $routeParameters): ?string
    {
        if (array_key_exists($paramName, $routeParameters)) {
            return $paramName;
        }

        return null;
    }

    /**
     * Apply explicit binding overrides and parse custom key names from route paths.
     *
     * @param array<string, BindingMeta> $bindings
     *
     * @return array<string, BindingMeta>
     */
    private function applyExplicitOverrides(array $bindings, MatchedRoute $matchedRoute): array
    {
        // Apply explicit bindings: these always override implicit resolution
        foreach ($this->explicitBindings as $explicit) {
            $paramName = $explicit->parameter;

            if (!$matchedRoute->hasParameter($paramName)) {
                continue;
            }

            $existing = $bindings[$paramName] ?? null;

            $bindings[$paramName] = new BindingMeta(
                class: $explicit->modelClass,
                keyName: $existing !== null ? $existing->keyName : 'id',
                keyType: $existing !== null ? $existing->keyType : 'string',
                scoped: $existing !== null ? $existing->scoped : false,
                parentRelation: $existing?->parentRelation,
                authzPolicy: $existing?->authzPolicy,
                customResolver: $explicit->resolverClass,
            );
        }

        // Parse {param:key} custom key names from the route path
        $routePath = $matchedRoute->route->path;
        foreach ($bindings as $paramName => $meta) {
            $customKey = $this->parseCustomKey($routePath, $paramName);
            if ($customKey !== null && $customKey !== $meta->keyName) {
                $bindings[$paramName] = new BindingMeta(
                    class: $meta->class,
                    keyName: $customKey,
                    keyType: 'string',
                    scoped: $meta->scoped,
                    parentRelation: $meta->parentRelation,
                    authzPolicy: $meta->authzPolicy,
                    customResolver: $meta->customResolver,
                );
            }
        }

        return $bindings;
    }

    /**
     * Parse a custom key name from {param:key} syntax in the route path.
     */
    private function parseCustomKey(string $routePath, string $paramName): ?string
    {
        $pattern = '{' . $paramName . ':';
        $pos = strpos($routePath, $pattern);

        if ($pos === false) {
            return null;
        }

        $start = $pos + strlen($pattern);
        $end = strpos($routePath, '}', $start);

        if ($end === false) {
            return null;
        }

        return substr($routePath, $start, $end - $start);
    }
}
