<?php

declare(strict_types=1);

namespace Pulsar\Api\OpenApi;

use Pulsar\Api\Internal;
use Pulsar\Api\OpenApi\Attribute\ApiDoc;
use Pulsar\Api\OpenApi\Attribute\ApiParam;
use Pulsar\Api\OpenApi\Attribute\ApiResponse;
use Pulsar\Routing\Route;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;

use function array_map;
use function class_exists;
use function count;
use function explode;
use function is_array;
use function is_string;
use function str_contains;

/**
 * Scans registered routes and collects endpoint metadata at build time.
 *
 * Reads `#[ApiDoc]`, `#[ApiParam]`, and `#[ApiResponse]` attributes from
 * route handler classes and methods to produce `EndpointMetadata` instances
 * that the `SpecGenerator` consumes.
 */
#[Internal(reason: 'Build-time scanner, not part of the public API')]
final readonly class EndpointScanner
{
    /**
     * Scan a list of routes and produce endpoint metadata.
     *
     * @param list<Route> $routes Registered framework routes
     *
     * @return list<EndpointMetadata>
     */
    public function scan(array $routes): array
    {
        return array_map($this->scanRoute(...), $routes);
    }

    /**
     * Scan a single route and extract endpoint metadata.
     */
    private function scanRoute(Route $route): EndpointMetadata
    {
        $methods = array_map(static fn($m) => $m->value, $route->methods);

        [$handlerClass, $handlerMethod] = $this->resolveHandler($route->handler);

        $doc = null;
        $params = [];
        $responses = [];

        if ($handlerClass !== null) {
            $doc = $this->extractClassDoc($handlerClass);
        }

        if ($handlerClass !== null && $handlerMethod !== null) {
            $methodDoc = $this->extractMethodDoc($handlerClass, $handlerMethod);
            if ($methodDoc !== null) {
                $doc = $methodDoc;
            }
            $params = $this->extractParams($handlerClass, $handlerMethod);
            $responses = $this->extractResponses($handlerClass, $handlerMethod);
        }

        return new EndpointMetadata(
            path: $route->path,
            methods: $methods,
            handlerClass: $handlerClass,
            handlerMethod: $handlerMethod,
            doc: $doc,
            params: $params,
            responses: $responses,
            middleware: $route->middleware,
        );
    }

    /**
     * Resolve a route handler to a class name and method name.
     *
     * @param mixed $handler
     *
     * @return array{0: class-string|null, 1: string|null}
     */
    private function resolveHandler(mixed $handler): array
    {
        // [ClassName::class, 'method']
        if (is_array($handler) && count($handler) === 2 && is_string($handler[0]) && is_string($handler[1])) {
            /** @var class-string $class */
            $class = $handler[0];
            return [$class, $handler[1]];
        }

        // 'ClassName::method'
        if (is_string($handler) && str_contains($handler, '::')) {
            /** @var array{0: string, 1: string} $parts */
            $parts = explode('::', $handler, 2);
            /** @var class-string $class */
            $class = $parts[0];
            return [$class, $parts[1]];
        }

        // Invocable class: 'ClassName'
        if (is_string($handler) && class_exists($handler)) {
            /** @var class-string $handler */
            return [$handler, '__invoke'];
        }

        return [null, null];
    }

    /**
     * Extract ApiDoc from a class-level attribute.
     *
     * @param class-string $className
     */
    private function extractClassDoc(string $className): ?ApiDoc
    {
        if (!class_exists($className)) {
            return null;
        }

        $reflection = new ReflectionClass($className);
        $attrs = $reflection->getAttributes(ApiDoc::class);

        if ($attrs === []) {
            return null;
        }

        return $attrs[0]->newInstance();
    }

    /**
     * Extract ApiDoc from a method-level attribute.
     *
     * @param class-string $className
     */
    private function extractMethodDoc(string $className, string $methodName): ?ApiDoc
    {
        $method = $this->reflectMethod($className, $methodName);
        if ($method === null) {
            return null;
        }

        $attrs = $method->getAttributes(ApiDoc::class);
        if ($attrs === []) {
            return null;
        }

        return $attrs[0]->newInstance();
    }

    /**
     * Extract all ApiParam attributes from a method.
     *
     * @param class-string $className
     *
     * @return list<ApiParam>
     */
    private function extractParams(string $className, string $methodName): array
    {
        $method = $this->reflectMethod($className, $methodName);
        if ($method === null) {
            return [];
        }

        $attrs = $method->getAttributes(ApiParam::class);

        return array_map(
            static fn(ReflectionAttribute $attr): ApiParam => $attr->newInstance(),
            $attrs,
        );
    }

    /**
     * Extract all ApiResponse attributes from a method.
     *
     * @param class-string $className
     *
     * @return list<ApiResponse>
     */
    private function extractResponses(string $className, string $methodName): array
    {
        $method = $this->reflectMethod($className, $methodName);
        if ($method === null) {
            return [];
        }

        $attrs = $method->getAttributes(ApiResponse::class);

        return array_map(
            static fn(ReflectionAttribute $attr): ApiResponse => $attr->newInstance(),
            $attrs,
        );
    }

    /**
     * Safely reflect a method, returning null if it doesn't exist.
     *
     * @param class-string $className
     */
    private function reflectMethod(string $className, string $methodName): ?ReflectionMethod
    {
        if (!class_exists($className)) {
            return null;
        }

        $reflection = new ReflectionClass($className);

        if (!$reflection->hasMethod($methodName)) {
            return null;
        }

        return $reflection->getMethod($methodName);
    }
}
