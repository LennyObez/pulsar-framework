<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Http;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use RuntimeException;

#[CoversClass(MiddlewarePipeline::class)]
#[CoversClass(MiddlewareRegistry::class)]
final class MiddlewareStackTest extends TestCase
{
    /**
     * @return class-string<MiddlewareInterface>
     */
    private static function mwClass(string $name): string
    {
        /** @var class-string<MiddlewareInterface> */
        return $name;
    }

    // -------------------------------------------------------------------------
    // MiddlewarePipeline tests
    // -------------------------------------------------------------------------

    #[Test]
    public function singleMiddlewarePassesRequestToHandler(): void
    {
        $pipeline = new MiddlewarePipeline();
        $pipeline->pipe(new MwsAddHeaderMiddleware('X-Test', 'value'));

        $request = new ServerRequest(method: 'GET', uri: '/');
        $handler = new FinalHandler(Response::json(['ok' => true]));

        $response = $pipeline->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('value', $response->getHeaderLine('X-Test'));
    }

    #[Test]
    public function multipleMiddlewareExecuteInFifoOrder(): void
    {
        $order = [];

        $pipeline = new MiddlewarePipeline();
        $pipeline->pipe(new MwsOrderRecorder('first', $order));
        $pipeline->pipe(new MwsOrderRecorder('second', $order));
        $pipeline->pipe(new MwsOrderRecorder('third', $order));

        $request = new ServerRequest(method: 'GET', uri: '/');
        $handler = new FinalHandler(Response::json([]));

        $pipeline->process($request, $handler);

        self::assertSame(['first', 'second', 'third'], $order);
    }

    #[Test]
    public function middlewareShortCircuitReturnsEarlyResponse(): void
    {
        $pipeline = new MiddlewarePipeline();
        $pipeline->pipe(new MwsShortCircuitMiddleware(429));
        $pipeline->pipe(new MwsAddHeaderMiddleware('X-Should-Not-Appear', 'yes'));

        $request = new ServerRequest(method: 'GET', uri: '/');
        $handler = new FinalHandler(Response::json(['ok' => true]));

        $response = $pipeline->process($request, $handler);

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('X-Should-Not-Appear'));
    }

    #[Test]
    public function pipelineCountAndEmptyChecks(): void
    {
        $pipeline = new MiddlewarePipeline();

        self::assertTrue($pipeline->isEmpty());
        self::assertSame(0, $pipeline->count());

        $pipeline->pipe(new MwsAddHeaderMiddleware('X-A', 'a'));
        $pipeline->pipe(new MwsAddHeaderMiddleware('X-B', 'b'));

        self::assertFalse($pipeline->isEmpty());
        self::assertSame(2, $pipeline->count());
    }

    #[Test]
    public function middlewarePipelineDispatchCallable(): void
    {
        $pipeline = new MiddlewarePipeline();
        $pipeline->pipe(new MwsAddHeaderMiddleware('X-Via', 'dispatch'));

        $request = new ServerRequest(method: 'GET', uri: '/');
        $response = $pipeline->dispatch($request, fn($req) => Response::json(['dispatched' => true]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('dispatch', $response->getHeaderLine('X-Via'));
    }

    #[Test]
    public function middlewarePipelineRejectsHandleWithoutProcess(): void
    {
        $pipeline = new MiddlewarePipeline();
        $pipeline->pipe(new MwsAddHeaderMiddleware('X-A', 'a'));

        $request = new ServerRequest(method: 'GET', uri: '/');

        $this->expectException(RuntimeException::class);
        $pipeline->handle($request);
    }

    #[Test]
    public function middlewarePipelineWithSetHandler(): void
    {
        $pipeline = new MiddlewarePipeline();
        $pipeline->pipe(new MwsAddHeaderMiddleware('X-Handler', 'set'));
        $pipeline->setHandler(new FinalHandler(Response::json(['from_handler' => true])));

        $request = new ServerRequest(method: 'GET', uri: '/');
        $response = $pipeline->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('set', $response->getHeaderLine('X-Handler'));
    }

    #[Test]
    public function middlewareCanModifyRequestAttributes(): void
    {
        $pipeline = new MiddlewarePipeline();
        $pipeline->pipe(new MwsAddAttributeMiddleware('user_id', 42));

        $request = new ServerRequest(method: 'GET', uri: '/');

        $capturedRequest = null;
        $response = $pipeline->dispatch(
            $request,
            function ($req) use (&$capturedRequest) {
                $capturedRequest = $req;
                return Response::json([]);
            },
        );

        self::assertNotNull($capturedRequest);
        self::assertSame(42, $capturedRequest->getAttribute('user_id'));
    }

    // -------------------------------------------------------------------------
    // MiddlewareRegistry tests
    // -------------------------------------------------------------------------

    #[Test]
    public function middlewareRegistryResolvesAlias(): void
    {
        $registry = new MiddlewareRegistry();
        $registry->alias('auth', self::mwClass(MwsAddHeaderMiddleware::class));

        $resolved = $registry->resolve('auth');

        self::assertCount(1, $resolved);
        self::assertSame(MwsAddHeaderMiddleware::class, $resolved[0]);
    }

    #[Test]
    public function middlewareRegistryResolvesGroup(): void
    {
        $registry = new MiddlewareRegistry();
        $registry->group('api', [
            self::mwClass(MwsAddHeaderMiddleware::class),
            self::mwClass(MwsShortCircuitMiddleware::class),
        ]);

        $resolved = $registry->resolve('api');

        self::assertCount(2, $resolved);
        self::assertContains(MwsAddHeaderMiddleware::class, $resolved);
        self::assertContains(MwsShortCircuitMiddleware::class, $resolved);
    }

    #[Test]
    public function middlewareRegistryResolvesGroupWithAlias(): void
    {
        $registry = new MiddlewareRegistry();
        $registry->alias('ratelimit', self::mwClass(MwsShortCircuitMiddleware::class));
        $registry->group('web', [
            self::mwClass('ratelimit'),
            self::mwClass(MwsAddHeaderMiddleware::class),
        ]);

        $resolved = $registry->resolve('web');

        self::assertCount(2, $resolved);
    }

    #[Test]
    public function middlewareRegistryThrowsOnCircularReference(): void
    {
        $registry = new MiddlewareRegistry();
        $registry->alias('a', self::mwClass('b'));
        $registry->alias('b', self::mwClass('a'));

        $this->expectException(RuntimeException::class);
        $registry->resolve('a');
    }

    #[Test]
    public function middlewareRegistryHasAliasAndHasGroup(): void
    {
        $registry = new MiddlewareRegistry();
        $registry->alias('my-alias', self::mwClass(MwsAddHeaderMiddleware::class));
        $registry->group('my-group', [self::mwClass(MwsAddHeaderMiddleware::class)]);

        self::assertTrue($registry->hasAlias('my-alias'));
        self::assertFalse($registry->hasAlias('my-group'));
        self::assertTrue($registry->hasGroup('my-group'));
        self::assertFalse($registry->hasGroup('my-alias'));
    }

    #[Test]
    public function middlewareRegistryUnknownNameReturnedAsIs(): void
    {
        $registry = new MiddlewareRegistry();

        $resolved = $registry->resolve(MwsAddHeaderMiddleware::class);

        self::assertCount(1, $resolved);
        self::assertSame(MwsAddHeaderMiddleware::class, $resolved[0]);
    }
}

// -------------------------------------------------------------------------
// Test doubles
// -------------------------------------------------------------------------

/** @internal */
final class FinalHandler implements RequestHandlerInterface
{
    public function __construct(private readonly ResponseInterface $response) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->response;
    }
}

/** @internal */
final class MwsAddHeaderMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly string $name,
        private readonly string $value,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        return $response->withHeader($this->name, $this->value);
    }
}

/** @internal */
final class MwsShortCircuitMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly int $statusCode) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return new Response(statusCode: $this->statusCode);
    }
}

/** @internal */
final class MwsOrderRecorder implements MiddlewareInterface
{
    /** @param list<string> $order */
    public function __construct(
        private readonly string $label,
        public array &$order,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->order[] = $this->label;
        return $handler->handle($request);
    }
}

/** @internal */
final class MwsAddAttributeMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly string $key,
        private readonly mixed $attributeValue,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request->withAttribute($this->key, $this->attributeValue));
    }
}
