<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Routing\Binding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Method;
use Pulsar\Routing\Binding\BindingMeta;
use Pulsar\Routing\Binding\BindingResolver;
use Pulsar\Routing\Binding\CompiledBindingMap;
use Pulsar\Routing\Binding\Contract\AuthorizationHookInterface;
use Pulsar\Routing\Binding\Contract\ModelResolverPort;
use Pulsar\Routing\Binding\ModelBinder;
use Pulsar\Routing\Binding\ModelBindingConfig;
use Pulsar\Routing\Binding\ModelBindingMiddleware;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Routing\Route;
use stdClass;

#[CoversClass(ModelBindingMiddleware::class)]
final class ModelBindingMiddlewareTest extends TestCase
{
    #[Test]
    public function fullLifecycleResolvesModelAndPassesToHandler(): void
    {
        $model = new stdClass();
        $model->id = 42;

        $resolver = $this->createStub(ModelResolverPort::class);
        $resolver->method('resolve')->willReturn($model);

        $binder = $this->createBinder($resolver, [
            'users.show' => [
                'user' => new BindingMeta(class: stdClass::class, keyName: 'id', keyType: 'string'),
            ],
        ]);

        $authHook = $this->createStub(AuthorizationHookInterface::class);
        $authHook->method('authorize')->willReturn(true);

        $identity = $this->createAuthenticatedIdentity();

        $config = new ModelBindingConfig(preset: 'standard');
        $middleware = new ModelBindingMiddleware(
            $binder,
            $config,
            $authHook,
            identityResolver: static fn() => $identity,
        );

        $route = new Route([Method::GET], '/users/{user}', [MiddlewareTestController::class, 'show'], 'users.show');
        $matched = new MatchedRoute($route, ['user' => '42']);

        $request = new ServerRequest('GET', '/users/42');
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

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertNotNull($capturedRequest);
        self::assertInstanceOf(ServerRequestInterface::class, $capturedRequest);
        self::assertSame($model, $capturedRequest->getAttribute('_model_user'));
        self::assertSame(['user' => $model], $capturedRequest->getAttribute('_bound_models'));
    }

    #[Test]
    public function modelNotFoundReturns404(): void
    {
        $resolver = $this->createStub(ModelResolverPort::class);
        $resolver->method('resolve')->willReturn(null);

        $binder = $this->createBinder($resolver, [
            'users.show' => [
                'user' => new BindingMeta(class: stdClass::class, keyName: 'id', keyType: 'string'),
            ],
        ]);

        $authHook = $this->createStub(AuthorizationHookInterface::class);
        $config = new ModelBindingConfig(preset: 'standard');
        $middleware = new ModelBindingMiddleware($binder, $config, $authHook);

        $route = new Route([Method::GET], '/users/{user}', [MiddlewareTestController::class, 'show'], 'users.show');
        $matched = new MatchedRoute($route, ['user' => '999']);

        $request = new ServerRequest('GET', '/users/999');
        $request = $request->withAttribute('_route', $matched);

        $handler = $this->createStub(RequestHandlerInterface::class);

        $response = $middleware->process($request, $handler);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function noMatchedRoutePassesThrough(): void
    {
        $resolver = $this->createStub(ModelResolverPort::class);
        $binder = $this->createBinder($resolver, []);

        $authHook = $this->createStub(AuthorizationHookInterface::class);
        $config = new ModelBindingConfig();
        $middleware = new ModelBindingMiddleware($binder, $config, $authHook);

        $request = new ServerRequest('GET', '/');

        $expectedResponse = new Response(statusCode: 200, body: 'passthrough');
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($expectedResponse);

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function noBoundParametersPassesThrough(): void
    {
        $resolver = $this->createStub(ModelResolverPort::class);
        $binder = $this->createBinder($resolver, []);

        $authHook = $this->createStub(AuthorizationHookInterface::class);
        $config = new ModelBindingConfig();
        $middleware = new ModelBindingMiddleware($binder, $config, $authHook);

        $route = new Route([Method::GET], '/static', [MiddlewareTestController::class, 'index']);
        $matched = new MatchedRoute($route, []);

        $request = new ServerRequest('GET', '/static');
        $request = $request->withAttribute('_route', $matched);

        $expectedResponse = new Response(statusCode: 200, body: 'static');
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($expectedResponse);

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function jsonAcceptHeaderReturnsJsonErrorResponse(): void
    {
        $resolver = $this->createStub(ModelResolverPort::class);
        $resolver->method('resolve')->willReturn(null);

        $binder = $this->createBinder($resolver, [
            'users.show' => [
                'user' => new BindingMeta(class: stdClass::class, keyName: 'id', keyType: 'string'),
            ],
        ]);

        $authHook = $this->createStub(AuthorizationHookInterface::class);
        $config = new ModelBindingConfig(preset: 'standard');
        $middleware = new ModelBindingMiddleware($binder, $config, $authHook);

        $route = new Route([Method::GET], '/users/{user}', [MiddlewareTestController::class, 'show'], 'users.show');
        $matched = new MatchedRoute($route, ['user' => '999']);

        $request = new ServerRequest('GET', '/users/999', ['Accept' => 'application/json']);
        $request = $request->withAttribute('_route', $matched);

        $handler = $this->createStub(RequestHandlerInterface::class);

        $response = $middleware->process($request, $handler);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function closureHandlerBinderReturnsEmptyPassesThrough(): void
    {
        $resolver = $this->createStub(ModelResolverPort::class);
        $binder = $this->createBinder($resolver, []);

        $authHook = $this->createStub(AuthorizationHookInterface::class);
        $config = new ModelBindingConfig();
        $middleware = new ModelBindingMiddleware($binder, $config, $authHook);

        // Closure handler — binder returns [] since it cannot reflect closures
        $route = new Route([Method::GET], '/users/{user}', static fn() => null, 'users.show');
        $matched = new MatchedRoute($route, ['user' => '42']);

        $request = new ServerRequest('GET', '/users/42');
        $request = $request->withAttribute('_route', $matched);

        $expectedResponse = new Response(statusCode: 200, body: 'ok');
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($expectedResponse);

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * @param array<string, array<string, BindingMeta>> $compiledMap
     */
    private function createBinder(ModelResolverPort $resolver, array $compiledMap): ModelBinder
    {
        $bindingResolver = new BindingResolver(compiledMap: new CompiledBindingMap($compiledMap));

        return new ModelBinder(
            $resolver,
            $bindingResolver,
            $this->createStub(ContainerInterface::class),
        );
    }

    private function createAuthenticatedIdentity(): IdentityInterface
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);
        $identity->method('id')->willReturn('user-1');

        return $identity;
    }
}

final class MiddlewareTestController
{
    public function show(stdClass $user): void {}

    public function index(): void {}
}
