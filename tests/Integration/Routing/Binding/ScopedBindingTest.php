<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Routing\Binding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Method;
use Pulsar\Routing\Binding\BindingMeta;
use Pulsar\Routing\Binding\BindingPreset;
use Pulsar\Routing\Binding\BindingResolver;
use Pulsar\Routing\Binding\BindingScope;
use Pulsar\Routing\Binding\CompiledBindingMap;
use Pulsar\Routing\Binding\Contract\AuthorizationHookInterface;
use Pulsar\Routing\Binding\Contract\ModelResolverPort;
use Pulsar\Routing\Binding\ModelBinder;
use Pulsar\Routing\Binding\ModelBindingConfig;
use Pulsar\Routing\Binding\ModelBindingMiddleware;
use Pulsar\Routing\Binding\ResolutionContext;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Routing\Route;
use stdClass;

#[CoversClass(ModelBinder::class)]
#[CoversClass(ModelBindingMiddleware::class)]
final class ScopedBindingTest extends TestCase
{
    #[Test]
    public function parentChildResolutionUsesResolveScoped(): void
    {
        $parent = new stdClass();
        $parent->id = 1;
        $child = new stdClass();
        $child->id = 5;

        $resolver = $this->createMock(ModelResolverPort::class);
        $resolver->expects(self::once())->method('resolve')->willReturn($parent);
        $resolver->expects(self::once())
            ->method('resolveScoped')
            ->with(
                stdClass::class,
                'id',
                self::anything(),
                $parent,
                'posts',
                self::isInstanceOf(ResolutionContext::class),
            )
            ->willReturn($child);

        $compiledMap = new CompiledBindingMap([
            'posts.show' => [
                'user' => new BindingMeta(class: stdClass::class, keyName: 'id', keyType: 'string'),
                'post' => new BindingMeta(
                    class: stdClass::class,
                    keyName: 'id',
                    keyType: 'string',
                    scope: BindingScope::Contained,
                    parentRelation: 'posts',
                ),
            ],
        ]);

        $bindingResolver = new BindingResolver(compiledMap: $compiledMap);
        $binder = new ModelBinder(
            $resolver,
            $bindingResolver,
            $this->createStub(ContainerInterface::class),
        );

        $authHook = $this->createStub(AuthorizationHookInterface::class);
        $authHook->method('authorize')->willReturn(true);

        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);
        $identity->method('id')->willReturn('user-1');

        $config = new ModelBindingConfig(preset: BindingPreset::Standard);
        $middleware = new ModelBindingMiddleware(
            $binder,
            $config,
            $authHook,
            identityResolver: static fn() => $identity,
        );

        $route = new Route(
            [Method::GET],
            '/users/{user}/posts/{post}',
            [ScopedTestController::class, 'show'],
            'posts.show',
        );
        $matched = new MatchedRoute($route, ['user' => '1', 'post' => '5']);

        $request = new ServerRequest('GET', '/users/1/posts/5');
        $request = $request->withAttribute('_route', $matched);

        $capturedRequest = null;
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->with(self::callback(static function ($req) use (&$capturedRequest): bool {
                $capturedRequest = $req;
                return true;
            }))
            ->willReturn(new Response(statusCode: 200, body: 'ok'));

        $this->dispatch($middleware, $request, $handler);

        self::assertNotNull($capturedRequest);
        self::assertInstanceOf(ServerRequestInterface::class, $capturedRequest);
        self::assertSame($parent, $capturedRequest->getAttribute('_model_user'));
        self::assertSame($child, $capturedRequest->getAttribute('_model_post'));
    }

    #[Test]
    public function missingScopedModelReturns404(): void
    {
        $parent = new stdClass();

        $resolver = $this->createStub(ModelResolverPort::class);
        $resolver->method('resolve')->willReturn($parent);
        $resolver->method('resolveScoped')->willReturn(null);

        $compiledMap = new CompiledBindingMap([
            'posts.show' => [
                'user' => new BindingMeta(class: stdClass::class, keyName: 'id', keyType: 'string'),
                'post' => new BindingMeta(
                    class: stdClass::class,
                    keyName: 'id',
                    keyType: 'string',
                    scope: BindingScope::Contained,
                    parentRelation: 'posts',
                ),
            ],
        ]);

        $bindingResolver = new BindingResolver(compiledMap: $compiledMap);
        $binder = new ModelBinder(
            $resolver,
            $bindingResolver,
            $this->createStub(ContainerInterface::class),
        );

        $authHook = $this->createStub(AuthorizationHookInterface::class);
        $config = new ModelBindingConfig(preset: BindingPreset::Standard);
        $middleware = new ModelBindingMiddleware($binder, $config, $authHook);

        $route = new Route(
            [Method::GET],
            '/users/{user}/posts/{post}',
            [ScopedTestController::class, 'show'],
            'posts.show',
        );
        $matched = new MatchedRoute($route, ['user' => '1', 'post' => '999']);

        $request = new ServerRequest('GET', '/users/1/posts/999');
        $request = $request->withAttribute('_route', $matched);

        $handler = $this->createStub(RequestHandlerInterface::class);

        $response = $this->dispatch($middleware, $request, $handler);

        self::assertSame(404, $response->getStatusCode());
    }

    /**
     * Run the middleware the way the kernel runs it.
     *
     * The middleware does not read `_route`: the kernel passes the route it is
     * dispatching to the pipeline, which binds a per-dispatch copy of the
     * middleware to it. These tests write the same route into both places, which
     * is what a real dispatch does; that the two cannot be made to DISAGREE is
     * the subject of DispatchedRouteAuthorityTest.
     */
    private function dispatch(
        ModelBindingMiddleware $middleware,
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        /** @var MatchedRoute|null $route */
        $route = $request->getAttribute('_route');

        $bound = $route === null ? $middleware : $middleware->forDispatchedRoute($route);

        return $bound->process($request, $handler);
    }
}

final class ScopedTestController
{
    public function show(stdClass $user, stdClass $post): void {}
}
