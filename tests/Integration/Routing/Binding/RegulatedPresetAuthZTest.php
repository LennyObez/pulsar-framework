<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Routing\Binding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Method;
use Pulsar\Routing\Attribute\PublicRoute;
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

use function array_values;

#[CoversClass(ModelBindingMiddleware::class)]
final class RegulatedPresetAuthZTest extends TestCase
{
    #[Test]
    public function regulatedPresetRunsAuthorizationOnEveryBoundModel(): void
    {
        $model = new stdClass();

        $authHook = $this->createMock(AuthorizationHookInterface::class);
        $authHook->expects(self::once())
            ->method('authorize')
            ->willReturn(true);

        $middleware = $this->buildMiddleware(
            models: ['user' => $model],
            config: new ModelBindingConfig(preset: 'banking'),
            authHook: $authHook,
            authenticated: true,
        );

        $request = $this->buildRequest(
            path: '/users/{user}',
            params: ['user' => '42'],
            handler: [RegulatedTestController::class, 'show'],
            routeName: 'test.show',
        );

        $handler = $this->createPassthroughHandler();
        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function regulatedPresetDenyReturns403(): void
    {
        $model = new stdClass();

        $authHook = $this->createStub(AuthorizationHookInterface::class);
        $authHook->method('authorize')->willReturn(false);

        $middleware = $this->buildMiddleware(
            models: ['user' => $model],
            config: new ModelBindingConfig(preset: 'healthcare'),
            authHook: $authHook,
            authenticated: true,
        );

        $request = $this->buildRequest(
            path: '/users/{user}',
            params: ['user' => '42'],
            handler: [RegulatedTestController::class, 'show'],
            routeName: 'test.show',
        );

        $handler = $this->createStub(RequestHandlerInterface::class);
        $response = $middleware->process($request, $handler);

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function regulatedPresetNoAuthenticatedIdentityReturns401(): void
    {
        $model = new stdClass();

        $authHook = $this->createStub(AuthorizationHookInterface::class);

        $middleware = $this->buildMiddleware(
            models: ['user' => $model],
            config: new ModelBindingConfig(preset: 'legal'),
            authHook: $authHook,
            authenticated: false,
        );

        $request = $this->buildRequest(
            path: '/users/{user}',
            params: ['user' => '42'],
            handler: [RegulatedTestController::class, 'show'],
            routeName: 'test.show',
        );

        $handler = $this->createStub(RequestHandlerInterface::class);
        $response = $middleware->process($request, $handler);

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function regulatedPresetAuthBypassOnNonPublicRouteReturns403(): void
    {
        $model = new stdClass();

        $authHook = $this->createStub(AuthorizationHookInterface::class);

        $middleware = $this->buildMiddleware(
            models: ['user' => $model],
            config: new ModelBindingConfig(preset: 'banking'),
            authHook: $authHook,
            authenticated: true,
        );

        $request = $this->buildRequest(
            path: '/users/{user}',
            params: ['user' => '42'],
            handler: [RegulatedTestController::class, 'show'],
            routeName: 'test.show',
            withoutAuthorization: true,
        );

        $handler = $this->createStub(RequestHandlerInterface::class);
        $response = $middleware->process($request, $handler);

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function regulatedPresetAuthBypassOnPublicRoutePassesThrough(): void
    {
        $model = new stdClass();

        $authHook = $this->createMock(AuthorizationHookInterface::class);
        $authHook->expects(self::never())->method('authorize');

        $middleware = $this->buildMiddleware(
            models: ['user' => $model],
            config: new ModelBindingConfig(preset: 'banking'),
            authHook: $authHook,
            authenticated: true,
        );

        $request = $this->buildRequest(
            path: '/public/{user}',
            params: ['user' => '42'],
            handler: [RegulatedPublicTestController::class, 'show'],
            routeName: 'test.public',
            withoutAuthorization: true,
        );

        $handler = $this->createPassthroughHandler();
        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function permissivePresetNoErrorWhenUnauthenticated(): void
    {
        $model = new stdClass();

        $authHook = $this->createStub(AuthorizationHookInterface::class);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('debug');

        $middleware = $this->buildMiddleware(
            models: ['user' => $model],
            config: new ModelBindingConfig(preset: 'standard'),
            authHook: $authHook,
            authenticated: false,
            logger: $logger,
        );

        $request = $this->buildRequest(
            path: '/users/{user}',
            params: ['user' => '42'],
            handler: [RegulatedTestController::class, 'show'],
            routeName: 'test.show',
        );

        $handler = $this->createPassthroughHandler();
        $response = $middleware->process($request, $handler);

        // Permissive preset: no 401, passes through with a debug log
        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * @param array<string, object> $models
     */
    private function buildMiddleware(
        array $models,
        ModelBindingConfig $config,
        AuthorizationHookInterface $authHook,
        bool $authenticated = true,
        ?LoggerInterface $logger = null,
    ): ModelBindingMiddleware {
        $resolver = $this->createStub(ModelResolverPort::class);

        $values = array_values($models);
        $callIndex = 0;
        $resolver->method('resolve')->willReturnCallback(static function () use ($values, &$callIndex): ?object {
            return $values[$callIndex++] ?? null;
        });

        $metas = [];
        foreach ($models as $param => $model) {
            $metas[$param] = new BindingMeta(class: $model::class, keyName: 'id', keyType: 'string');
        }

        $compiledMap = new CompiledBindingMap(['test.show' => $metas, 'test.public' => $metas]);
        $bindingResolver = new BindingResolver(compiledMap: $compiledMap);

        $binder = new ModelBinder(
            $resolver,
            $bindingResolver,
            $this->createStub(ContainerInterface::class),
        );

        $identityResolver = null;
        if ($authenticated) {
            $identity = $this->createStub(IdentityInterface::class);
            $identity->method('isAuthenticated')->willReturn(true);
            $identity->method('id')->willReturn('user-1');
            $identityResolver = static fn() => $identity;
        } else {
            $identityResolver = static fn() => null;
        }

        return new ModelBindingMiddleware(
            $binder,
            $config,
            $authHook,
            identityResolver: $identityResolver,
            logger: $logger,
        );
    }

    /**
     * @param array<string, string> $params
     * @param callable|class-string|array{0: class-string, 1: string} $handler
     */
    private function buildRequest(
        string $path,
        array $params,
        mixed $handler,
        string $routeName,
        bool $withoutAuthorization = false,
    ): ServerRequest {
        $attributes = [];
        if ($withoutAuthorization) {
            $attributes['_without_authorization'] = true;
        }

        $route = new Route([Method::GET], $path, $handler, $routeName, attributes: $attributes);
        $matched = new MatchedRoute($route, $params);

        $request = new ServerRequest('GET', $path);

        return $request->withAttribute('_route', $matched);
    }

    private function createPassthroughHandler(): RequestHandlerInterface
    {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(statusCode: 200, body: 'ok'));

        return $handler;
    }
}

final class RegulatedTestController
{
    public function show(stdClass $user): void {}
}

#[PublicRoute(reason: 'Public endpoint for testing')]
final class RegulatedPublicTestController
{
    public function show(stdClass $user): void {}
}
