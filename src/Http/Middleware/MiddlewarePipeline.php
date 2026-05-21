<?php

declare(strict_types=1);

namespace Pulsar\Http\Middleware;

use InvalidArgumentException;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface as PsrMiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as PsrRequestHandlerInterface;
use Pulsar\Api\Internal;
use Pulsar\Container\ContainerInterface;
use RuntimeException;
use Throwable;

use function array_reverse;
use function class_exists;
use function count;
use function get_debug_type;
use function sprintf;

/**
 * PSR-15 middleware pipeline.
 *
 * Executes a stack of PSR-15 middleware around a core request handler.
 */
#[Internal]
final class MiddlewarePipeline implements MiddlewarePipelineInterface, PsrRequestHandlerInterface
{
    /**
     * @var list<PsrMiddlewareInterface|class-string<PsrMiddlewareInterface>>
     */
    private array $middleware = [];

    /**
     * @var list<PsrMiddlewareInterface>|null
     */
    private ?array $resolvedMiddleware = null;

    private ?PsrRequestHandlerInterface $fallbackHandler = null;

    /**
     * Cached middleware chain for the current fallback handler.
     *
     * Built on first handle() call and reused for subsequent requests.
     * Invalidated when middleware stack or fallback handler changes.
     */
    private ?PsrRequestHandlerInterface $cachedChain = null;

    public function __construct(
        private readonly ?ContainerInterface $container = null,
    ) {}

    /**
     * Add middleware to the pipeline.
     *
     * Middleware is executed in the order it is added (FIFO).
     *
     * @param PsrMiddlewareInterface|class-string<PsrMiddlewareInterface> $middleware
     */
    public function pipe(PsrMiddlewareInterface|string $middleware): self
    {
        $this->middleware[] = $middleware;
        $this->resolvedMiddleware = null;
        $this->cachedChain = null;

        return $this;
    }

    /**
     * Process a request through the middleware stack with a final handler.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function process(ServerRequestInterface $request, PsrRequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->fallbackHandler !== $handler) {
            $this->fallbackHandler = $handler;
            $this->cachedChain = null;
        }

        return $this->handle($request);
    }

    /**
     * Handle a request through the middleware stack.
     *
     * Uses the fallback handler set via process() as the core handler.
     */
    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->fallbackHandler === null) {
            throw new RuntimeException('No fallback handler set. Call process() or setHandler() first.');
        }

        $chain = $this->cachedChain ??= $this->createPipeline($this->fallbackHandler);

        return $chain->handle($request);
    }

    /**
     * Set the fallback handler for handle() calls.
     */
    public function setHandler(PsrRequestHandlerInterface $handler): void
    {
        $this->fallbackHandler = $handler;
        $this->cachedChain = null;
    }

    /**
     * Process a request through the middleware stack with a callable handler.
     *
     * @param callable(ServerRequestInterface): ResponseInterface $handler
     */
    public function dispatch(ServerRequestInterface $request, callable $handler): ResponseInterface
    {
        $wrappedHandler = new CallableRequestHandler($handler);
        $pipeline = $this->createPipeline($wrappedHandler);

        return $pipeline->handle($request);
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
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function isEmpty(): bool
    {
        return $this->middleware === [];
    }

    /**
     * Build a chained RequestHandler from the middleware stack and a final handler.
     */
    private function createPipeline(PsrRequestHandlerInterface $handler): PsrRequestHandlerInterface
    {
        $resolved = $this->resolvedMiddleware ??= $this->resolveAllMiddleware();

        $current = $handler;

        foreach ($resolved as $middleware) {
            $current = new MiddlewareHandler($middleware, $current);
        }

        return $current;
    }

    /**
     * @return list<PsrMiddlewareInterface>
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
     * @param PsrMiddlewareInterface|class-string<PsrMiddlewareInterface> $middleware
     */
    private function resolveMiddleware(PsrMiddlewareInterface|string $middleware): PsrMiddlewareInterface
    {
        if ($middleware instanceof PsrMiddlewareInterface) {
            return $middleware;
        }

        if ($this->container !== null && $this->container->has($middleware)) {
            $resolved = $this->container->get($middleware);

            // F2.15: prefer an explicit type guard over `assert()`. With
            // `zend.assertions=-1` (typical prod) the assertion compiles
            // out and a non-conforming binding would silently slip into
            // the pipeline, exploding deeper inside `process()`. A real
            // throw guarantees the failure surfaces with a precise
            // diagnostic regardless of assertion mode.
            if (!$resolved instanceof PsrMiddlewareInterface) {
                throw new InvalidArgumentException(sprintf(
                    'Container binding "%s" resolved to %s, expected %s.',
                    $middleware,
                    get_debug_type($resolved),
                    PsrMiddlewareInterface::class,
                ));
            }

            return $resolved;
        }

        if (class_exists($middleware)) {
            // Try container resolution for classes with constructor dependencies
            if ($this->container !== null) {
                try {
                    $resolved = $this->container->get($middleware);
                    if ($resolved instanceof PsrMiddlewareInterface) {
                        return $resolved;
                    }
                } catch (Throwable) {
                    // Container resolution failed; fall through to direct instantiation
                }
            }

            return new $middleware();
        }

        throw new InvalidArgumentException(sprintf(
            'Middleware "%s" could not be resolved. Ensure it is a valid class or registered in the container.',
            $middleware,
        ));
    }
}
