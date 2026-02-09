<?php

declare(strict_types=1);

namespace Pulsar\Routing\Binding;

use NoDiscard;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Api;
use Pulsar\Container\ContainerInterface;
use Pulsar\Routing\Binding\Contract\ModelResolverPort;
use Pulsar\Routing\MatchedRoute;

use function ctype_digit;
use function is_array;
use function is_string;
use function ltrim;

/**
 * Resolves route parameters into domain model instances.
 *
 * Orchestrates the binding pipeline: determines which parameters need
 * model resolution, validates key types, delegates to the appropriate
 * resolver, and supports scoped (parent/child) binding chains.
 */
#[Api(since: '1.0.0-rc.11')]
final readonly class ModelBinder
{
    public function __construct(
        private ModelResolverPort $defaultResolver,
        private BindingResolver $bindingResolver,
        private ContainerInterface $container,
    ) {}

    /**
     * Resolve all model bindings for a matched route.
     *
     * @return array<string, object> Resolved models keyed by parameter name
     *
     * @throws ModelBindingException When a model cannot be found or key validation fails
     */
    #[NoDiscard]
    public function bind(
        MatchedRoute $matchedRoute,
        ServerRequestInterface $request,
        ResolutionContext $context,
    ): array {
        $handler = $matchedRoute->getHandler();
        $handlerInfo = $this->resolveHandlerInfo($handler);

        if ($handlerInfo === null) {
            return [];
        }

        [$controllerClass, $controllerMethod] = $handlerInfo;

        $bindingMetas = $this->bindingResolver->resolveForRoute(
            $matchedRoute,
            $controllerClass,
            $controllerMethod,
        );

        if ($bindingMetas === []) {
            return [];
        }

        $resolved = [];
        $previousModel = null;

        foreach ($bindingMetas as $paramName => $meta) {
            $rawValue = $matchedRoute->parameter($paramName);
            if ($rawValue === null) {
                continue;
            }

            $keyValue = $this->coerceKeyValue($paramName, $meta, $rawValue);
            $resolver = $this->getResolver($meta);

            if ($meta->scoped && $previousModel !== null && $meta->parentRelation !== null) {
                $model = $resolver->resolveScoped(
                    $meta->class,
                    $meta->keyName,
                    $keyValue,
                    $previousModel,
                    $meta->parentRelation,
                    $context,
                );
            } else {
                $model = $resolver->resolve(
                    $meta->class,
                    $meta->keyName,
                    $keyValue,
                    $context,
                );
            }

            if ($model === null) {
                throw ModelBindingException::modelNotFound($meta->class, $meta->keyName, $rawValue);
            }

            $resolved[$paramName] = $model;
            $previousModel = $model;
        }

        return $resolved;
    }

    /**
     * Validate and coerce the raw route parameter value to the declared key type.
     */
    private function coerceKeyValue(string $paramName, BindingMeta $meta, string $rawValue): string|int
    {
        if ($meta->keyType === 'int') {
            // Strict integer validation: must be a string of digits, optionally with leading sign
            if (!ctype_digit($rawValue) && !ctype_digit(ltrim($rawValue, '-'))) {
                throw ModelBindingException::invalidKeyType($paramName, 'int', $rawValue);
            }

            // Reject negative values for model keys
            if ($rawValue[0] === '-') {
                throw ModelBindingException::invalidKeyType($paramName, 'int', $rawValue);
            }

            return (int) $rawValue;
        }

        return $rawValue;
    }

    /**
     * Determine the appropriate resolver for a binding.
     */
    private function getResolver(BindingMeta $meta): ModelResolverPort
    {
        if ($meta->customResolver !== null) {
            /** @var ModelResolverPort */
            return $this->container->get($meta->customResolver);
        }

        return $this->defaultResolver;
    }

    /**
     * Extract controller class and method from a route handler.
     *
     * @param array{0: class-string, 1: string}|callable|class-string $handler
     * @return array{0: class-string, 1: string}|null
     */
    private function resolveHandlerInfo(array|string|callable $handler): ?array
    {
        // Array handler: [ControllerClass::class, 'method']
        if (is_array($handler) && isset($handler[0], $handler[1]) && is_string($handler[0]) && is_string($handler[1])) {
            /** @var class-string $class */
            $class = $handler[0];
            return [$class, $handler[1]];
        }

        // String handler: invokable controller class
        if (is_string($handler) && class_exists($handler)) {
            /** @var class-string $handler */
            return [$handler, '__invoke'];
        }

        // Closures and other callables cannot be reflected for type hints
        return null;
    }
}
