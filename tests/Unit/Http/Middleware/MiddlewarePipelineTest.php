<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Middleware;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\Container;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Request;
use Pulsar\Http\Response;

#[CoversClass(MiddlewarePipeline::class)]
final class MiddlewarePipelineTest extends TestCase
{
    private function createRequest(): Request
    {
        return new Request(
            method: Method::GET,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag(),
            body: '',
        );
    }

    #[Test]
    public function emptyPipelinePassesToHandler(): void
    {
        $pipeline = new MiddlewarePipeline();
        $request = $this->createRequest();

        $response = $pipeline->handle($request, fn() => Response::text('handled'));

        self::assertSame('handled', $response->body);
    }

    #[Test]
    public function middlewareIsExecutedInOrder(): void
    {
        /** @var list<string> $order */
        $order = [];
        $pipeline = new MiddlewarePipeline();

        $middleware1 = new class ($order) implements MiddlewareInterface {
            /**
             * @param list<string> $order
             * @phpstan-ignore property.onlyWritten
             */
            public function __construct(private array &$order) {}

            public function process(Request $request, callable $next): Response
            {
                $this->order[] = 'before1';
                $response = $next($request);
                $this->order[] = 'after1';
                return $response;
            }
        };

        $middleware2 = new class ($order) implements MiddlewareInterface {
            /**
             * @param list<string> $order
             * @phpstan-ignore property.onlyWritten
             */
            public function __construct(private array &$order) {}

            public function process(Request $request, callable $next): Response
            {
                $this->order[] = 'before2';
                $response = $next($request);
                $this->order[] = 'after2';
                return $response;
            }
        };

        $pipeline->pipe($middleware1)->pipe($middleware2);

        $pipeline->handle($this->createRequest(), function () use (&$order) {
            $order[] = 'handler';
            return Response::text('ok');
        });

        self::assertSame(['before1', 'before2', 'handler', 'after2', 'after1'], $order);
    }

    #[Test]
    public function middlewareCanModifyRequest(): void
    {
        $pipeline = new MiddlewarePipeline();

        $middleware = new class implements MiddlewareInterface {
            public function process(Request $request, callable $next): Response
            {
                return $next($request->withAttribute('modified', true));
            }
        };

        $pipeline->pipe($middleware);
        $receivedAttribute = null;

        $pipeline->handle($this->createRequest(), function (Request $request) use (&$receivedAttribute) {
            $receivedAttribute = $request->attribute('modified');
            return Response::text('ok');
        });

        self::assertTrue($receivedAttribute);
    }

    #[Test]
    public function middlewareCanModifyResponse(): void
    {
        $pipeline = new MiddlewarePipeline();

        $middleware = new class implements MiddlewareInterface {
            public function process(Request $request, callable $next): Response
            {
                $response = $next($request);
                return $response->withHeader('X-Modified', 'true');
            }
        };

        $pipeline->pipe($middleware);

        $response = $pipeline->handle($this->createRequest(), fn() => Response::text('ok'));

        self::assertSame('true', $response->headers->first('X-Modified'));
    }

    #[Test]
    public function middlewareCanShortCircuit(): void
    {
        $pipeline = new MiddlewarePipeline();
        $handlerCalled = false;

        $middleware = new class implements MiddlewareInterface {
            public function process(Request $request, callable $next): Response
            {
                return Response::text('short-circuited');
            }
        };

        $pipeline->pipe($middleware);

        $response = $pipeline->handle($this->createRequest(), function () use (&$handlerCalled) {
            $handlerCalled = true;
            return Response::text('handler');
        });

        self::assertFalse($handlerCalled);
        self::assertSame('short-circuited', $response->body);
    }

    #[Test]
    public function middlewareCanBeResolvedFromClassName(): void
    {
        $pipeline = new MiddlewarePipeline();

        $pipeline->pipe(AddHeaderMiddleware::class);

        $response = $pipeline->handle($this->createRequest(), fn() => Response::text('ok'));

        self::assertSame('added', $response->headers->first('X-Test'));
    }

    #[Test]
    public function middlewareCanBeResolvedFromContainer(): void
    {
        $container = new Container();
        $instance = new AddHeaderMiddleware();
        $container->instance(AddHeaderMiddleware::class, $instance);

        $pipeline = new MiddlewarePipeline($container);
        $pipeline->pipe(AddHeaderMiddleware::class);

        $response = $pipeline->handle($this->createRequest(), fn() => Response::text('ok'));

        self::assertSame('added', $response->headers->first('X-Test'));
    }

    #[Test]
    public function invalidMiddlewareThrowsException(): void
    {
        $pipeline = new MiddlewarePipeline();
        // @phpstan-ignore argument.type
        $pipeline->pipe('NonExistentMiddleware');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('could not be resolved');

        $pipeline->handle($this->createRequest(), fn() => Response::text('ok'));
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
        /** @var list<string> $order */
        $order = [];
        $pipeline = new MiddlewarePipeline();

        for ($i = 1; $i <= 3; $i++) {
            $n = $i;
            $middleware = new class ($order, $n) implements MiddlewareInterface {
                /**
                 * @param list<string> $order
                 * @phpstan-ignore property.onlyWritten
                 */
                public function __construct(private array &$order, private readonly int $n) {}

                public function process(Request $request, callable $next): Response
                {
                    $this->order[] = "before{$this->n}";
                    $response = $next($request);
                    $this->order[] = "after{$this->n}";
                    return $response;
                }
            };
            $pipeline->pipe($middleware);
        }

        $pipeline->handle($this->createRequest(), function () use (&$order) {
            $order[] = 'handler';
            return Response::text('ok');
        });

        self::assertSame(
            ['before1', 'before2', 'before3', 'handler', 'after3', 'after2', 'after1'],
            $order,
        );
    }

    #[Test]
    public function shortCircuitCascadePreventsDownstreamMiddleware(): void
    {
        /** @var list<string> $order */
        $order = [];
        $pipeline = new MiddlewarePipeline();

        // First middleware passes through
        $pipeline->pipe(new class ($order) implements MiddlewareInterface {
            /**
             * @param list<string> $order
             * @phpstan-ignore property.onlyWritten
             */
            public function __construct(private array &$order) {}

            public function process(Request $request, callable $next): Response
            {
                $this->order[] = 'first';
                return $next($request);
            }
        });

        // Second middleware short-circuits
        $pipeline->pipe(new class ($order) implements MiddlewareInterface {
            /**
             * @param list<string> $order
             * @phpstan-ignore property.onlyWritten
             */
            public function __construct(private array &$order) {}

            public function process(Request $request, callable $next): Response
            {
                $this->order[] = 'blocker';
                return Response::text('blocked');
            }
        });

        // Third middleware should never execute
        $pipeline->pipe(new class ($order) implements MiddlewareInterface {
            /**
             * @param list<string> $order
             * @phpstan-ignore property.onlyWritten
             */
            public function __construct(private array &$order) {}

            public function process(Request $request, callable $next): Response
            {
                $this->order[] = 'third';
                return $next($request);
            }
        });

        $response = $pipeline->handle($this->createRequest(), function () use (&$order) {
            $order[] = 'handler';
            return Response::text('ok');
        });

        self::assertSame(['first', 'blocker'], $order);
        self::assertSame('blocked', $response->body);
    }
}

class AddHeaderMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        $response = $next($request);
        return $response->withHeader('X-Test', 'added');
    }
}
