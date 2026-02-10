<?php

declare(strict_types=1);

namespace Pulsar\Http\Middleware;

use InvalidArgumentException;
use Pulsar\Api\Internal;
use Pulsar\Container\ContainerInterface;
use Pulsar\Http\Request;
use Pulsar\Http\Response;

use function array_reverse;
use function assert;
use function count;
use function sprintf;

/**
 * Executes a stack of middleware around a core handler.
 */
#[Internal]
final class MiddlewarePipeline implements MiddlewarePipelineInterface
{
    /**
     * @var list<MiddlewareInterface|class-string<MiddlewareInterface>>
     */
    private array $middleware = [];

    /**
     * Cached resolved+reversed middleware list.
     *
     * Populated on first `handle()` call. Subsequent calls reuse this list,
     * skipping both container resolution and array_reverse().
     *
     * Invalidated when new middleware is piped via `pipe()`.
     *
     * @var list<MiddlewareInterface>|null
     */
    private ?array $resolvedMiddleware = null;

    public function __construct(
        private readonly ?ContainerInterface $container = null,
    ) {}

    /**
     * Add middleware to the pipeline.
     *
     * Middleware is executed in the order it is added (FIFO).
     *
     * @param MiddlewareInterface|class-string<MiddlewareInterface> $middleware
     */
    public function pipe(MiddlewareInterface|string $middleware): static
    {
        $this->middleware[] = $middleware;
        $this->resolvedMiddleware = null; // Invalidate cache

        return $this;
    }

    /**
     * Process a request through the middleware stack.
     *
     * @param Request $request The incoming request
     * @param callable(Request): Response $handler The core handler
     * @return Response The response
     */
    public function handle(Request $request, callable $handler): Response
    {
        $pipeline = $this->createPipeline($handler);
        return $pipeline($request);
    }

    /**
     * Create the middleware pipeline function.
     *
     * @param callable(Request): Response $handler
     * @return callable(Request): Response
     */
    private function createPipeline(callable $handler): callable
    {
        // Start with the core handler
        $next = $handler;

        // Resolve and cache the reversed middleware list on first call
        $resolved = $this->resolvedMiddleware ??= $this->resolveAllMiddleware();

        // Wrap in middleware from inside out (already reversed)
        foreach ($resolved as $middlewareInstance) {
            $next = $this->createLayer($middlewareInstance, $next);
        }

        return $next;
    }

    /**
     * Resolve all middleware instances and return them in reversed order.
     *
     * @return list<MiddlewareInterface>
     */
    private function resolveAllMiddleware(): array
    {
        $resolved = [];

        foreach (array_reverse($this->middleware) as $middleware) {
            $resolved[] = $this->resolveMiddleware($middleware);
        }

        return $resolved;
    }

    /**
     * Create a single middleware layer.
     *
     * @param callable(Request): Response $next
     * @return callable(Request): Response
     */
    private function createLayer(MiddlewareInterface $middleware, callable $next): callable
    {
        return static fn(Request $request): Response => $middleware->process($request, $next);
    }

    /**
     * Resolve a middleware instance.
     *
     * @param MiddlewareInterface|class-string<MiddlewareInterface> $middleware
     */
    private function resolveMiddleware(MiddlewareInterface|string $middleware): MiddlewareInterface
    {
        if ($middleware instanceof MiddlewareInterface) {
            return $middleware;
        }

        if ($this->container !== null && $this->container->has($middleware)) {
            $resolved = $this->container->get($middleware);
            assert($resolved instanceof MiddlewareInterface);
            return $resolved;
        }

        if (class_exists($middleware)) {
            return new $middleware();
        }

        throw new InvalidArgumentException(sprintf(
            'Middleware "%s" could not be resolved. Ensure it is a valid class or registered in the container.',
            $middleware,
        ));
    }

    /**
     * Get the count of middleware in the pipeline.
     */
    public function count(): int
    {
        return count($this->middleware);
    }

    /**
     * Check if the pipeline is empty.
     */
    public function isEmpty(): bool
    {
        return $this->middleware === [];
    }
}
