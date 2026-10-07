<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Middleware;

use ArrayObject;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Container\Container;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;

#[CoversClass(MiddlewarePipeline::class)]
final class MiddlewarePipelineTest extends TestCase
{
    private function createRequest(): ServerRequest
    {
        return new ServerRequest(method: 'GET', uri: '/');
    }

    #[Test]
    public function emptyPipelinePassesToHandler(): void
    {
        $pipeline = new MiddlewarePipeline();
        $request = $this->createRequest();

        $response = $pipeline->dispatch($request, fn() => Response::text('handled'));

        self::assertSame('handled', (string) $response->getBody());
    }

    #[Test]
    public function middlewareIsExecutedInOrder(): void
    {
        /** @var ArrayObject<int, string> $order */
        $order = new ArrayObject();
        $pipeline = new MiddlewarePipeline();

        $middleware1 = new class ($order) implements MiddlewareInterface {
            /** @param ArrayObject<int, string> $order */
            public function __construct(private readonly ArrayObject $order) {}

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $this->order->append('before1');
                $response = $handler->handle($request);
                $this->order->append('after1');
                return $response;
            }
        };

        $middleware2 = new class ($order) implements MiddlewareInterface {
            /** @param ArrayObject<int, string> $order */
            public function __construct(private readonly ArrayObject $order) {}

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $this->order->append('before2');
                $response = $handler->handle($request);
                $this->order->append('after2');
                return $response;
            }
        };

        $pipeline->pipe($middleware1)->pipe($middleware2);

        $pipeline->dispatch($this->createRequest(), function () use ($order) {
            $order->append('handler');
            return Response::text('ok');
        });

        self::assertSame(['before1', 'before2', 'handler', 'after2', 'after1'], $order->getArrayCopy());
    }

    #[Test]
    public function prependedMiddlewareRunsBeforeAlreadyPipedMiddleware(): void
    {
        /** @var ArrayObject<int, string> $order */
        $order = new ArrayObject();
        $pipeline = new MiddlewarePipeline();

        $record = static function (string $label) use ($order): MiddlewareInterface {
            return new class ($order, $label) implements MiddlewareInterface {
                /** @param ArrayObject<int, string> $order */
                public function __construct(private readonly ArrayObject $order, private readonly string $label) {}

                public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
                {
                    $this->order->append("before:{$this->label}");
                    $response = $handler->handle($request);
                    $this->order->append("after:{$this->label}");

                    return $response;
                }
            };
        };

        // "framework" is piped first; "project" is prepended, so it must wrap it.
        $pipeline->pipe($record('framework'));
        $pipeline->prepend($record('project'));

        $pipeline->dispatch($this->createRequest(), function () use ($order) {
            $order->append('handler');

            return Response::text('ok');
        });

        self::assertSame(
            ['before:project', 'before:framework', 'handler', 'after:framework', 'after:project'],
            $order->getArrayCopy(),
        );
    }

    #[Test]
    public function aPrependedMiddlewareObservesTheRequestBeforeARewriterMutatesTheUri(): void
    {
        $pipeline = new MiddlewarePipeline();

        // A framework-style rewriter that strips a "/nl" prefix from the path.
        $rewriter = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $uri = $request->getUri()->withPath('/coaching');

                return $handler->handle($request->withUri($uri));
            }
        };

        /** @var ArrayObject<string, string> $observed */
        $observed = new ArrayObject();
        $project = new class ($observed) implements MiddlewareInterface {
            /** @param ArrayObject<string, string> $observed */
            public function __construct(private readonly ArrayObject $observed) {}

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $this->observed['path'] = $request->getUri()->getPath();

                return $handler->handle($request);
            }
        };

        $pipeline->pipe($rewriter);
        $pipeline->prepend($project);

        $request = new ServerRequest(method: 'GET', uri: '/nl/coaching');
        $pipeline->dispatch($request, fn() => Response::text('ok'));

        self::assertSame('/nl/coaching', $observed['path'] ?? null, 'The prepended middleware must see the pre-rewrite path');
    }

    #[Test]
    public function restoreFromSnapshotReplacesStackAndClearsCachedChain(): void
    {
        /** @var ArrayObject<int, string> $order */
        $order = new ArrayObject();
        $pipeline = new MiddlewarePipeline();

        $record = static function (string $label) use ($order): MiddlewareInterface {
            return new class ($order, $label) implements MiddlewareInterface {
                /** @param ArrayObject<int, string> $order */
                public function __construct(private readonly ArrayObject $order, private readonly string $label) {}

                public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
                {
                    $this->order->append($this->label);

                    return $handler->handle($request);
                }
            };
        };

        $pipeline->pipe($record('A'));
        $snapshot = $pipeline->snapshot();
        self::assertCount(1, $snapshot);

        $pipeline->pipe($record('B'));
        self::assertSame(2, $pipeline->count());

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return Response::text('ok');
            }
        };

        // Prime the cached chain with the [A, B] stack.
        $pipeline->process($this->createRequest(), $handler);

        // Restoring the [A] snapshot must clear the cached chain so the next
        // process() rebuilds from the restored stack rather than re-running B
        // from the stale cached chain (same handler, so process() alone would
        // not invalidate it).
        $pipeline->restoreFromSnapshot($snapshot);
        self::assertSame(1, $pipeline->count());

        $pipeline->process($this->createRequest(), $handler);

        self::assertSame(['A', 'B', 'A'], $order->getArrayCopy());
    }

    #[Test]
    public function middlewareCanModifyRequest(): void
    {
        $pipeline = new MiddlewarePipeline();

        $middleware = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request->withAttribute('modified', true));
            }
        };

        $pipeline->pipe($middleware);
        $receivedAttribute = null;

        $pipeline->dispatch($this->createRequest(), function (ServerRequestInterface $request) use (&$receivedAttribute) {
            $receivedAttribute = $request->getAttribute('modified');
            return Response::text('ok');
        });

        self::assertTrue($receivedAttribute);
    }

    #[Test]
    public function middlewareCanModifyResponse(): void
    {
        $pipeline = new MiddlewarePipeline();

        $middleware = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $response = $handler->handle($request);
                return $response->withHeader('X-Modified', 'true');
            }
        };

        $pipeline->pipe($middleware);

        $response = $pipeline->dispatch($this->createRequest(), fn() => Response::text('ok'));

        self::assertSame(['true'], $response->getHeader('X-Modified'));
    }

    #[Test]
    public function middlewareCanShortCircuit(): void
    {
        $pipeline = new MiddlewarePipeline();
        $handlerCalled = false;

        $middleware = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return Response::text('short-circuited');
            }
        };

        $pipeline->pipe($middleware);

        $response = $pipeline->dispatch($this->createRequest(), function () use (&$handlerCalled) {
            $handlerCalled = true;
            return Response::text('handler');
        });

        self::assertFalse($handlerCalled);
        self::assertSame('short-circuited', (string) $response->getBody());
    }

    #[Test]
    public function middlewareCanBeResolvedFromClassName(): void
    {
        $pipeline = new MiddlewarePipeline();

        $pipeline->pipe(AddHeaderMiddleware::class);

        $response = $pipeline->dispatch($this->createRequest(), fn() => Response::text('ok'));

        self::assertSame(['added'], $response->getHeader('X-Test'));
    }

    #[Test]
    public function middlewareCanBeResolvedFromContainer(): void
    {
        $container = new Container();
        $instance = new AddHeaderMiddleware();
        $container->instance(AddHeaderMiddleware::class, $instance);

        $pipeline = new MiddlewarePipeline($container);
        $pipeline->pipe(AddHeaderMiddleware::class);

        $response = $pipeline->dispatch($this->createRequest(), fn() => Response::text('ok'));

        self::assertSame(['added'], $response->getHeader('X-Test'));
    }

    #[Test]
    public function invalidMiddlewareThrowsException(): void
    {
        $pipeline = new MiddlewarePipeline();
        /** @var class-string<MiddlewareInterface> $nonExistent */
        $nonExistent = trim('NonExistentMiddleware');
        $pipeline->pipe($nonExistent);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('could not be resolved');

        $pipeline->dispatch($this->createRequest(), fn() => Response::text('ok'));
    }

    #[Test]
    public function countReturnsMiddlewareCount(): void
    {
        $pipeline = new MiddlewarePipeline();

        self::assertSame(0, $pipeline->count());

        $pipeline->pipe(AddHeaderMiddleware::class);
        self::assertSame(1, $pipeline->count());

        $pipeline->pipe(AddHeaderMiddleware::class);
        self::assertSame(2, $pipeline->count());
    }

    #[Test]
    public function isEmptyReturnsCorrectValue(): void
    {
        $pipeline = new MiddlewarePipeline();

        self::assertTrue($pipeline->isEmpty());

        $pipeline->pipe(AddHeaderMiddleware::class);

        self::assertFalse($pipeline->isEmpty());
    }

    #[Test]
    public function fifoGuaranteeWithThreeMiddleware(): void
    {
        /** @var ArrayObject<int, string> $order */
        $order = new ArrayObject();
        $pipeline = new MiddlewarePipeline();

        for ($i = 1; $i <= 3; $i++) {
            $n = $i;
            $middleware = new class ($order, $n) implements MiddlewareInterface {
                /** @param ArrayObject<int, string> $order */
                public function __construct(private readonly ArrayObject $order, private readonly int $n) {}

                public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
                {
                    $this->order->append("before{$this->n}");
                    $response = $handler->handle($request);
                    $this->order->append("after{$this->n}");
                    return $response;
                }
            };
            $pipeline->pipe($middleware);
        }

        $pipeline->dispatch($this->createRequest(), function () use ($order) {
            $order->append('handler');
            return Response::text('ok');
        });

        self::assertSame(
            ['before1', 'before2', 'before3', 'handler', 'after3', 'after2', 'after1'],
            $order->getArrayCopy(),
        );
    }

    #[Test]
    public function shortCircuitCascadePreventsDownstreamMiddleware(): void
    {
        /** @var ArrayObject<int, string> $order */
        $order = new ArrayObject();
        $pipeline = new MiddlewarePipeline();

        // First middleware passes through
        $pipeline->pipe(new class ($order) implements MiddlewareInterface {
            /** @param ArrayObject<int, string> $order */
            public function __construct(private readonly ArrayObject $order) {}

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $this->order->append('first');
                return $handler->handle($request);
            }
        });

        // Second middleware short-circuits
        $pipeline->pipe(new class ($order) implements MiddlewareInterface {
            /** @param ArrayObject<int, string> $order */
            public function __construct(private readonly ArrayObject $order) {}

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $this->order->append('blocker');
                return Response::text('blocked');
            }
        });

        // Third middleware should never execute
        $pipeline->pipe(new class ($order) implements MiddlewareInterface {
            /** @param ArrayObject<int, string> $order */
            public function __construct(private readonly ArrayObject $order) {}

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $this->order->append('third');
                return $handler->handle($request);
            }
        });

        $response = $pipeline->dispatch($this->createRequest(), function () use ($order) {
            $order->append('handler');
            return Response::text('ok');
        });

        self::assertSame(['first', 'blocker'], $order->getArrayCopy());
        self::assertSame('blocked', (string) $response->getBody());
    }
}

class AddHeaderMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        return $response->withHeader('X-Test', 'added');
    }
}
