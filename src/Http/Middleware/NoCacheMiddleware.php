<?php

declare(strict_types=1);

namespace Pulsar\Http\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Api;
use Pulsar\Http\Attribute\NoCacheResponse;
use ReflectionException;
use ReflectionMethod;

use function is_array;
use function is_string;

/**
 * Applies anti-caching headers to responses from handlers annotated with
 * #[NoCacheResponse]. Prevents sensitive data (PII, financial records)
 * from being cached by browsers or intermediate proxies.
 *
 * The attribute lookup is cached per `ClassName::methodName` key. Route
 * handlers are resolved once at route registration and never change at
 * runtime, so the cache is safe for the entire process lifetime and
 * eliminates the per-request `ReflectionMethod` allocation (M-2
 * audit response).
 * @api
 */
#[Api(since: '1.0.0')]
final class NoCacheMiddleware implements MiddlewareInterface
{
    /**
     * Per-handler lookup cache: `"Class::method"` => has-attribute bool.
     *
     * @var array<string, bool>
     */
    private static array $attributeCache = [];

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if ($this->shouldApply($request)) {
            $response = $response
                ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate')
                ->withHeader('Pragma', 'no-cache')
                ->withHeader('Expires', '0');
        }

        return $response;
    }

    private function shouldApply(ServerRequestInterface $request): bool
    {
        // Check for the attribute marker set by the router
        $handler = $request->getAttribute('_controller');

        if ($handler === null) {
            return false;
        }

        // Support [ClassName, methodName] array format
        if (is_array($handler) && isset($handler[0], $handler[1]) && is_string($handler[0]) && is_string($handler[1])) {
            return $this->hasNoCacheAttribute($handler[0], $handler[1]);
        }

        // Support "ClassName::methodName" string format
        if (is_string($handler) && str_contains($handler, '::')) {
            $parts = explode('::', $handler, 2);
            if (!isset($parts[1])) {
                return false;
            }

            return $this->hasNoCacheAttribute($parts[0], $parts[1]);
        }

        return false;
    }

    private function hasNoCacheAttribute(string $class, string $method): bool
    {
        $cacheKey = $class . '::' . $method;

        if (isset(self::$attributeCache[$cacheKey])) {
            return self::$attributeCache[$cacheKey];
        }

        try {
            $ref = new ReflectionMethod($class, $method);

            if ($ref->getAttributes(NoCacheResponse::class) !== []) {
                return self::$attributeCache[$cacheKey] = true;
            }

            // Check class-level attribute
            $classRef = $ref->getDeclaringClass();

            return self::$attributeCache[$cacheKey] = $classRef->getAttributes(NoCacheResponse::class) !== [];
        } catch (ReflectionException) {
            return self::$attributeCache[$cacheKey] = false;
        }
    }
}
