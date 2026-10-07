<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Middleware;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Core\Kernel;
use Pulsar\Http\Attribute\NoCacheResponse;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Method;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\NoCacheMiddleware;
use Pulsar\Http\Middleware\PostRoutingPipeline;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Routing\Route;

#[CoversClass(NoCacheMiddleware::class)]
final class NoCacheMiddlewareTest extends TestCase
{
    private const string NO_STORE = 'no-store, no-cache, must-revalidate';

    #[Test]
    public function refusesToRunWhenNothingBoundADispatchedRoute(): void
    {
        // The shape a global registration takes: the pipeline runs before
        // routing and never calls forDispatchedRoute(). Silently returning the
        // response is what made this class inert for its whole existence.
        $middleware = new NoCacheMiddleware();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/ran without a dispatched route/');

        $middleware->process(new ServerRequest(method: 'GET', uri: '/'), $this->handler());
    }

    #[Test]
    public function refusalHappensBeforeTheHandlerRuns(): void
    {
        $middleware = new NoCacheMiddleware();
        $handler = new RecordingHandler();

        try {
            $middleware->process(new ServerRequest(method: 'GET', uri: '/'), $handler);
            self::fail('Expected a LogicException.');
        } catch (LogicException) {
            // Refusing after producing the sensitive body would already have
            // handed it to a cache that is about to be told it may keep it.
            self::assertFalse($handler->reached, 'The handler must not run when the control cannot work.');
        }
    }

    #[Test]
    public function appliesHeadersWhenTheHandlerMethodDeclaresTheAttribute(): void
    {
        $response = $this->dispatch([NoCacheTestController::class, 'sensitive']);

        self::assertSame(self::NO_STORE, $response->getHeaderLine('Cache-Control'));
        self::assertSame('no-cache', $response->getHeaderLine('Pragma'));
        self::assertSame('0', $response->getHeaderLine('Expires'));
    }

    #[Test]
    public function leavesAHandlerWithoutTheAttributeUntouched(): void
    {
        $response = $this->dispatch([NoCacheTestController::class, 'publicPage']);

        self::assertSame('', $response->getHeaderLine('Cache-Control'));
        self::assertSame('', $response->getHeaderLine('Pragma'));
        self::assertSame('', $response->getHeaderLine('Expires'));
    }

    #[Test]
    public function appliesForAClassLevelAttribute(): void
    {
        $response = $this->dispatch([NoCacheClassController::class, 'index']);

        self::assertSame(self::NO_STORE, $response->getHeaderLine('Cache-Control'));
    }

    #[Test]
    public function supportsTheStringHandlerFormat(): void
    {
        $response = $this->dispatch(NoCacheTestController::class . '::sensitive');

        self::assertSame(self::NO_STORE, $response->getHeaderLine('Cache-Control'));
    }

    #[Test]
    public function supportsAnInvokableControllerNamedByClassString(): void
    {
        $response = $this->dispatch(NoCacheInvokableController::class);

        self::assertSame(self::NO_STORE, $response->getHeaderLine('Cache-Control'));
    }

    #[Test]
    public function supportsAnAlreadyConstructedInvokableController(): void
    {
        $response = $this->dispatch(new NoCacheInvokableController());

        self::assertSame(self::NO_STORE, $response->getHeaderLine('Cache-Control'));
    }

    #[Test]
    public function aClosureHandlerCarriesNoDeclarationToEnforce(): void
    {
        // #[NoCacheResponse] targets methods and classes; a closure has nowhere
        // to put one, so there is nothing to enforce and nothing to claim.
        $response = $this->dispatch(static fn(): ResponseInterface => Response::text('OK'));

        self::assertSame('', $response->getHeaderLine('Cache-Control'));
    }

    #[Test]
    public function handlesNonExistentClassGracefully(): void
    {
        $response = $this->dispatch(['NonExistentClass', 'method']);

        self::assertSame('', $response->getHeaderLine('Cache-Control'));
    }

    #[Test]
    public function findsAClassLevelAttributeEvenWhenTheNamedMethodIsMissing(): void
    {
        $response = $this->dispatch([NoCacheClassController::class, 'renamedAway']);

        self::assertSame(self::NO_STORE, $response->getHeaderLine('Cache-Control'));
    }

    #[Test]
    public function bindingReturnsACopyAndLeavesTheSharedInstanceUnbound(): void
    {
        // Middleware are resolved once and reused for the process lifetime. A
        // route stored on the shared instance would be some other request's.
        $shared = new NoCacheMiddleware();
        $bound = $shared->forDispatchedRoute($this->matchedRoute([NoCacheTestController::class, 'sensitive']));

        self::assertNotSame($shared, $bound);

        $this->expectException(LogicException::class);
        $shared->process(new ServerRequest(method: 'GET', uri: '/'), $this->handler());
    }

    #[Test]
    public function routeLevelPipelineBindsTheDispatchedRoute(): void
    {
        // The exact call the kernel makes for route-level middleware. Before the
        // fix this middleware read a `_controller` request attribute that nothing
        // in the framework has ever written, so this dispatch produced a bare
        // response and the declaration was enforced nowhere.
        $matched = $this->matchedRoute([NoCacheTestController::class, 'sensitive']);
        $pipeline = new MiddlewarePipeline();
        $pipeline->pipe(new NoCacheMiddleware());

        $response = $pipeline->dispatch(
            new ServerRequest(method: 'GET', uri: '/statement'),
            static fn(ServerRequestInterface $request): ResponseInterface => Response::text('statement'),
            $matched,
        );

        self::assertSame(self::NO_STORE, $response->getHeaderLine('Cache-Control'));
    }

    #[Test]
    public function theKernelEnforcesTheDeclarationOnARouteCarryingTheMiddleware(): void
    {
        // End to end, through the real kernel: a route naming this middleware
        // and a controller declaring #[NoCacheResponse]. This is the wiring an
        // application writes, and it protected nothing before the fix.
        $kernel = new Kernel();
        $kernel->middlewareRegistry()->alias('no-cache', NoCacheMiddleware::class);
        $kernel->router()->add(new Route(
            methods: [Method::GET],
            path: '/statement',
            handler: [NoCacheTestController::class, 'sensitive'],
            middleware: ['no-cache'],
        ));

        $response = $kernel->handle(new ServerRequest(method: 'GET', uri: 'http://localhost/statement'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(self::NO_STORE, $response->getHeaderLine('Cache-Control'));
        self::assertSame('no-cache', $response->getHeaderLine('Pragma'));
        self::assertSame('0', $response->getHeaderLine('Expires'));
    }

    #[Test]
    public function oneRegistrationInThePostRoutingPipelineCoversEveryDeclaration(): void
    {
        // The other supported wiring, and the one the attribute's own
        // documentation points at: registered once, it enforces every
        // #[NoCacheResponse] in the application. The routes below name no
        // middleware at all.
        $kernel = new Kernel();
        $kernel->container()->get(PostRoutingPipeline::class)->pipe(new NoCacheMiddleware());
        $kernel->router()->add(new Route(
            methods: [Method::GET],
            path: '/statement',
            handler: [NoCacheTestController::class, 'sensitive'],
        ));
        $kernel->router()->add(new Route(
            methods: [Method::GET],
            path: '/brochure',
            handler: [NoCacheTestController::class, 'publicPage'],
        ));

        $sensitive = $kernel->handle(new ServerRequest(method: 'GET', uri: 'http://localhost/statement'));
        self::assertSame(self::NO_STORE, $sensitive->getHeaderLine('Cache-Control'));

        // And it does not mark every response in the application no-store.
        $public = $kernel->handle(new ServerRequest(method: 'GET', uri: 'http://localhost/brochure'));
        self::assertSame('', $public->getHeaderLine('Cache-Control'));
    }

    /**
     * Run the middleware bound to a route with the given handler.
     */
    private function dispatch(mixed $handler): ResponseInterface
    {
        return new NoCacheMiddleware()
            ->forDispatchedRoute($this->matchedRoute($handler))
            ->process(new ServerRequest(method: 'GET', uri: '/'), $this->handler());
    }

    private function matchedRoute(mixed $handler): MatchedRoute
    {
        return new MatchedRoute(new Route(
            methods: [Method::GET],
            path: '/',
            handler: $handler,
        ));
    }

    private function handler(): RequestHandlerInterface
    {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        return $handler;
    }
}

// Test fixtures

/**
 * Records whether the downstream handler was ever reached.
 */
final class RecordingHandler implements RequestHandlerInterface
{
    public bool $reached = false;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->reached = true;

        return Response::text('OK');
    }
}

class NoCacheTestController
{
    #[NoCacheResponse]
    public function sensitive(): ResponseInterface
    {
        return Response::text('statement');
    }

    public function publicPage(): ResponseInterface
    {
        return Response::text('public');
    }
}

#[NoCacheResponse]
class NoCacheClassController
{
    public function index(): ResponseInterface
    {
        return Response::text('index');
    }
}

#[NoCacheResponse]
class NoCacheInvokableController
{
    public function __invoke(): ResponseInterface
    {
        return Response::text('invoked');
    }
}
