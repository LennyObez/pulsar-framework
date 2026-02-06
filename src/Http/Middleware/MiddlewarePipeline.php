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

use function array_reverse;
use function assert;
use function count;
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

        return $this;
    }

    /**
     * Process a request through the middleware stack with a final handler.
     */
    public function process(ServerRequestInterface $request, PsrRequestHandlerInterface $handler): ResponseInterface
    {
        $this->fallbackHandler = $handler;

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

        $pipeline = $this->createPipeline($this->fallbackHandler);

        return $pipeline->handle($request);
    }

    /**
     * Set the fallback handler for handle() calls.
     */
    public function setHandler(PsrRequestHandlerInterface $handler): void
    {
        $this->fallbackHandler = $handler;
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
            assert($resolved instanceof PsrMiddlewareInterface);

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
}
