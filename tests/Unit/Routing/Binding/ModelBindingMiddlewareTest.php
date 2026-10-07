<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Routing\Binding;

use Closure;
use JsonException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Http\Method;
use Pulsar\Routing\Attribute\PublicRoute;
use Pulsar\Routing\Binding\BindingMeta;
use Pulsar\Routing\Binding\BindingPreset;
use Pulsar\Routing\Binding\BindingResolver;
use Pulsar\Routing\Binding\CompiledBindingMap;
use Pulsar\Routing\Binding\Contract\AuthorizationHookInterface;
use Pulsar\Routing\Binding\Contract\ModelResolverPort;
use Pulsar\Routing\Binding\ModelBinder;
use Pulsar\Routing\Binding\ModelBindingConfig;
use Pulsar\Routing\Binding\ModelBindingException;
use Pulsar\Routing\Binding\ModelBindingMiddleware;
use Pulsar\Routing\Binding\ResolutionContext;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Routing\Route;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Tenancy\Tenant;
use Pulsar\Tenancy\TenantContext;
use stdClass;

#[CoversClass(ModelBindingMiddleware::class)]
final class ModelBindingMiddlewareTest extends TestCase
{
    private AuthorizationHookInterface&Stub $authHook;
    private ResponseInterface $nextResponse;

    protected function setUp(): void
    {
        $this->authHook = $this->createStub(AuthorizationHookInterface::class);
        $this->nextResponse = $this->createStub(ResponseInterface::class);
    }

    // ── Pass-through when no route or no parameters ──────────────────────

    #[Test]
    public function delegatesToHandlerWhenNoMatchedRoute(): void
    {
        $middleware = $this->buildMiddleware(binderModels: []);
        $request = $this->createRequestWithRoute(null);
        $handler = $this->createHandlerReturning($this->nextResponse);

        $response = $this->dispatch($middleware, $request, $handler);

        self::assertSame($this->nextResponse, $response);
    }

    #[Test]
    public function delegatesToHandlerWhenRouteHasEmptyParameters(): void
    {
        $route = new Route([Method::GET], '/static', [MiddlewareTestController::class, 'index'], 'static');
        $matched = new MatchedRoute($route, []);

        $middleware = $this->buildMiddleware(binderModels: []);
        $request = $this->createRequestWithRoute($matched);
        $handler = $this->createHandlerReturning($this->nextResponse);

        $response = $this->dispatch($middleware, $request, $handler);

        self::assertSame($this->nextResponse, $response);
    }

    // ── Successful binding ───────────────────────────────────────────────

    #[Test]
    public function attachesResolvedModelsToRequest(): void
    {
        $model = new stdClass();
        $model->name = 'TestUser';

        $this->authHook->method('authorize')->willReturn(true);

        $identity = $this->createAuthenticatedIdentity('user-1');
        $middleware = $this->buildMiddleware(
            binderModels: ['user' => $model],
            config: new ModelBindingConfig(preset: BindingPreset::Standard),
            identityResolver: static fn() => $identity,
        );

        $matched = $this->createMatchedRoute(['user' => '42']);
        $request = $this->createRequestWithRoute($matched);

        $capturedRequest = null;
        $handler = $this->createCapturingHandler($capturedRequest);

        $this->dispatch($middleware, $request, $handler);

        self::assertNotNull($capturedRequest);
        self::assertSame($model, $capturedRequest->getAttribute('_model_user'));
        self::assertSame(['user' => $model], $capturedRequest->getAttribute('_bound_models'));
    }

    #[Test]
    public function delegatesToHandlerWhenBinderReturnsEmptyArray(): void
    {
        // Use a handler whose method parameters don't match route params,
        // so the binder returns no bindings via implicit resolution
        $resolver = $this->createStub(ModelResolverPort::class);
        $binder = new ModelBinder(
            defaultResolver: $resolver,
            bindingResolver: new BindingResolver(compiledMap: new CompiledBindingMap([])),
            container: $this->createStub(ContainerInterface::class),
        );

        $middleware = new ModelBindingMiddleware(
            binder: $binder,
            config: new ModelBindingConfig(preset: BindingPreset::Standard),
            authHook: $this->authHook,
        );

        $route = new Route(
            [Method::GET],
            '/users/{user}',
            [MiddlewareTestNoBindingController::class, 'index'],
            'no-binding',
        );
        $matched = new MatchedRoute($route, ['user' => '42']);
        $request = $this->createRequestWithRoute($matched);
        $handler = $this->createHandlerReturning($this->nextResponse);

        $response = $this->dispatch($middleware, $request, $handler);

        self::assertSame($this->nextResponse, $response);
    }

    #[Test]
    public function attachesMultipleModelsToRequest(): void
    {
        $user = new stdClass();
        $user->name = 'User';
        $post = new stdClass();
        $post->title = 'Post';

        $this->authHook->method('authorize')->willReturn(true);

        $identity = $this->createAuthenticatedIdentity('user-1');
        $middleware = $this->buildMiddleware(
            binderModels: ['user' => $user, 'post' => $post],
            config: new ModelBindingConfig(preset: BindingPreset::Standard),
            identityResolver: static fn() => $identity,
            routeName: 'users.posts.show',
            routePath: '/users/{user}/posts/{post}',
            parameterNames: ['user', 'post'],
        );

        $route = new Route(
            [Method::GET],
            '/users/{user}/posts/{post}',
            [MiddlewareTestController::class, 'index'],
            'users.posts.show',
        );
        $matched = new MatchedRoute($route, ['user' => '1', 'post' => '5']);
        $request = $this->createRequestWithRoute($matched);

        $capturedRequest = null;
        $handler = $this->createCapturingHandler($capturedRequest);

        $this->dispatch($middleware, $request, $handler);

        self::assertNotNull($capturedRequest);
        self::assertSame($user, $capturedRequest->getAttribute('_model_user'));
        self::assertSame($post, $capturedRequest->getAttribute('_model_post'));
        self::assertSame(['user' => $user, 'post' => $post], $capturedRequest->getAttribute('_bound_models'));
    }

    // ── Binding exceptions ───────────────────────────────────────────────

    #[Test]
    public function notFoundExceptionReturns404(): void
    {
        // Resolver returns null => ModelBinder throws modelNotFound (404)
        $middleware = $this->buildMiddleware(
            binderModels: null, // null signals resolver returns null => exception
        );

        $matched = $this->createMatchedRoute(['user' => '999']);
        $request = $this->createRequestWithRoute($matched);
        $handler = $this->createHandlerReturning($this->nextResponse);

        $response = $this->dispatch($middleware, $request, $handler);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function notFoundExceptionReturnsJsonWhenAcceptHeaderPresent(): void
    {
        $middleware = $this->buildMiddleware(binderModels: null);

        $matched = $this->createMatchedRoute(['user' => '999']);
        $request = $this->createRequestWithRoute($matched, acceptJson: true);
        $handler = $this->createHandlerReturning($this->nextResponse);

        $response = $this->dispatch($middleware, $request, $handler);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function notFoundExceptionReturnsPlainTextWhenNoAcceptJson(): void
    {
        $middleware = $this->buildMiddleware(binderModels: null);

        $matched = $this->createMatchedRoute(['user' => '999']);
        $request = $this->createRequestWithRoute($matched, acceptJson: false);
        $handler = $this->createHandlerReturning($this->nextResponse);

        $response = $this->dispatch($middleware, $request, $handler);

        self::assertSame(404, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringNotContainsString('{', $body);
    }

    // ── Identity resolution ──────────────────────────────────────────────

    #[Test]
    public function identityResolvesFromInjectedClosure(): void
    {
        $model = new stdClass();
        $this->authHook->method('authorize')->willReturn(true);

        $identity = $this->createAuthenticatedIdentity('user-42');

        $resolverCalled = false;

        // No parameter, and that is the contract rather than a convenience: the
        // resolver is never handed the request, so no request attribute can
        // decide who the binding layer authorizes against.
        $identityResolver = static function () use ($identity, &$resolverCalled): IdentityInterface {
            $resolverCalled = true;

            return $identity;
        };

        $middleware = $this->buildMiddleware(
            binderModels: ['user' => $model],
            config: new ModelBindingConfig(preset: BindingPreset::Standard),
            identityResolver: $identityResolver,
        );

        $matched = $this->createMatchedRoute(['user' => '42']);
        $request = $this->createRequestWithRoute($matched);
        $handler = $this->createHandlerReturning($this->nextResponse);

        $this->dispatch($middleware, $request, $handler);

        self::assertTrue($resolverCalled);
    }

    #[Test]
    public function identityIsNullWhenNoResolverConfigured(): void
    {
        $model = new stdClass();

        $middleware = $this->buildMiddleware(
            binderModels: ['user' => $model],
            config: new ModelBindingConfig(preset: BindingPreset::Standard),
            identityResolver: null,
        );

        $matched = $this->createMatchedRoute(['user' => '42']);
        $request = $this->createRequestWithRoute($matched);
        $handler = $this->createHandlerReturning($this->nextResponse);

        // Permissive preset + null identity = skip authz, proceed normally
        $response = $this->dispatch($middleware, $request, $handler);

        self::assertSame($this->nextResponse, $response);
    }

    #[Test]
    public function identityIsNullWhenResolverReturnsNull(): void
    {
        $model = new stdClass();

        $middleware = $this->buildMiddleware(
            binderModels: ['user' => $model],
            config: new ModelBindingConfig(preset: BindingPreset::Standard),
            identityResolver: static fn() => null,
        );

        $matched = $this->createMatchedRoute(['user' => '42']);
        $request = $this->createRequestWithRoute($matched);
        $handler = $this->createHandlerReturning($this->nextResponse);

        // Permissive preset + null identity = skip authz, proceed normally
        $response = $this->dispatch($middleware, $request, $handler);

        self::assertSame($this->nextResponse, $response);
    }

    #[Test]
    public function identityIsNullWhenResolverReturnsUnauthenticatedIdentity(): void
    {
        $model = new stdClass();

        $unauthIdentity = $this->createStub(IdentityInterface::class);
        $unauthIdentity->method('isAuthenticated')->willReturn(false);

        $middleware = $this->buildMiddleware(
            binderModels: ['user' => $model],
            config: new ModelBindingConfig(preset: BindingPreset::Standard),
            identityResolver: static fn() => $unauthIdentity,
        );

        $matched = $this->createMatchedRoute(['user' => '42']);
        $request = $this->createRequestWithRoute($matched);
        $handler = $this->createHandlerReturning($this->nextResponse);

        // Permissive preset + unauthenticated = skip authz, proceed normally
        $response = $this->dispatch($middleware, $request, $handler);

        self::assertSame($this->nextResponse, $response);
    }

    // ── Authorization — standard (permissive) preset ─────────────────────

    #[Test]
    public function standardPresetAllowsWhenAuthHookReturnsTrue(): void
    {
        $model = new stdClass();
        $this->authHook->method('authorize')->willReturn(true);

        $identity = $this->createAuthenticatedIdentity('user-1');
        $middleware = $this->buildMiddleware(
            binderModels: ['user' => $model],
            config: new ModelBindingConfig(preset: BindingPreset::Standard),
            identityResolver: static fn() => $identity,
        );

        $matched = $this->createMatchedRoute(['user' => '42']);
        $request = $this->createRequestWithRoute($matched);
        $handler = $this->createHandlerReturning($this->nextResponse);

        $response = $this->dispatch($middleware, $request, $handler);

        self::assertSame($this->nextResponse, $response);
    }

    #[Test]
    public function standardPresetRefusesWhenAuthHookDenies(): void
    {
        $model = new stdClass();
        $this->authHook->method('authorize')->willReturn(false);

        $identity = $this->createAuthenticatedIdentity('user-1');
        $middleware = $this->buildMiddleware(
            binderModels: ['user' => $model],
            config: new ModelBindingConfig(preset: BindingPreset::Standard),
            identityResolver: static fn() => $identity,
        );

        $matched = $this->createMatchedRoute(['user' => '42']);
        $request = $this->createRequestWithRoute($matched);
        $handler = $this->createHandlerReturning($this->nextResponse);

        $response = $this->dispatch($middleware, $request, $handler);

        self::assertSame(404, $response->getStatusCode());
    }

    // ── Authorization policy propagation (declared authzPolicy) ──────────

    #[Test]
    public function enforcesDeclaredAuthorizationPolicyFromBindingMeta(): void
    {
        // Regression: the middleware must enforce the authorization policy
        // declared for the binding (carried on the compiled BindingMeta), not
        // a bare meta that silently downgrades every binding to the hook's
        // default 'view' permission and under-authorizes edit/delete routes.
        $model = new stdClass();

        $capturedMeta = null;
        $authHook = $this->createStub(AuthorizationHookInterface::class);
        $authHook->method('authorize')
            ->willReturnCallback(static function (
                IdentityInterface $identity,
                object $boundModel,
                BindingMeta $meta,
            ) use (&$capturedMeta): bool {
                $capturedMeta = $meta;
                return true;
            });

        $resolver = $this->createStub(ModelResolverPort::class);
        $resolver->method('resolve')->willReturn($model);

        // Compiled binding declares a non-default authorization policy.
        $map = [
            'users.show' => [
                'user' => new BindingMeta(
                    class: stdClass::class,
                    keyName: 'id',
                    keyType: 'string',
                    authzPolicy: 'delete',
                ),
            ],
        ];
        $binder = new ModelBinder(
            defaultResolver: $resolver,
            bindingResolver: new BindingResolver(compiledMap: new CompiledBindingMap($map)),
            container: $this->createStub(ContainerInterface::class),
        );

        $middleware = new ModelBindingMiddleware(
            binder: $binder,
            config: new ModelBindingConfig(preset: BindingPreset::Standard),
            authHook: $authHook,
            identityResolver: fn() => $this->createAuthenticatedIdentity('user-1'),
        );

        $matched = $this->createMatchedRoute(['user' => '42']);
        $request = $this->createRequestWithRoute($matched);
        $handler = $this->createHandlerReturning($this->nextResponse);

        $response = $this->dispatch($middleware, $request, $handler);

        self::assertSame($this->nextResponse, $response);
        self::assertInstanceOf(BindingMeta::class, $capturedMeta);
        self::assertSame('delete', $capturedMeta->authzPolicy);
        self::assertSame(stdClass::class, $capturedMeta->class);
    }

    #[Test]
    public function standardPresetSkipsAuthzWhenWithoutAuthorizationSet(): void
    {
        $model = new stdClass();
        // authHook would deny, but _without_authorization should bypass it
        $this->authHook->method('authorize')->willReturn(false);

        $identity = $this->createAuthenticatedIdentity('user-1');
        $middleware = $this->buildMiddleware(
            binderModels: ['user' => $model],
            config: new ModelBindingConfig(preset: BindingPreset::Standard),
            identityResolver: static fn() => $identity,
        );

        $matched = $this->createMatchedRoute(
            parameters: ['user' => '42'],
            attributes: ['_without_authorization' => true],
        );
        $request = $this->createRequestWithRoute($matched);
        $handler = $this->createHandlerReturning($this->nextResponse);

        $response = $this->dispatch($middleware, $request, $handler);

        // Permissive preset + _without_authorization = skip, delegate to handler
        self::assertSame($this->nextResponse, $response);
    }

    #[Test]
    public function standardPresetLogsWarningWhenNoIdentityAvailable(): void
    {
        $model = new stdClass();

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('debug')
            ->with(
                self::stringContains('authorization skipped'),
                self::callback(static fn(array $ctx): bool => isset($ctx['model'], $ctx['parameter'])),
            );

        $middleware = $this->buildMiddleware(
            binderModels: ['user' => $model],
            config: new ModelBindingConfig(preset: BindingPreset::Standard),
            identityResolver: static fn() => null,
            logger: $logger,
        );

        $matched = $this->createMatchedRoute(['user' => '42']);
        $request = $this->createRequestWithRoute($matched);
        $handler = $this->createHandlerReturning($this->nextResponse);

        $response = $this->dispatch($middleware, $request, $handler);

        self::assertSame($this->nextResponse, $response);
    }

    // ── Authorization — regulated preset ─────────────────────────────────

    #[Test]
    #[DataProvider('regulatedPresetProvider')]
    public function regulatedPresetReturnsForbiddenWhenAuthBypassWithoutPublicRoute(BindingPreset $preset): void
    {
        $model = new stdClass();

        $identity = $this->createAuthenticatedIdentity('user-1');
        $middleware = $this->buildMiddleware(
            binderModels: ['user' => $model],
            config: new ModelBindingConfig(preset: $preset),
            identityResolver: static fn() => $identity,
        );

        $matched = $this->createMatchedRoute(
            parameters: ['user' => '42'],
            attributes: ['_without_authorization' => true],
        );
        $request = $this->createRequestWithRoute($matched);
        $handler = $this->createHandlerReturning($this->nextResponse);

        $response = $this->dispatch($middleware, $request, $handler);

        self::assertSame(403, $response->getStatusCode());
    }

    /**
     * @return iterable<string, array{BindingPreset}>
     */
    public static function regulatedPresetProvider(): iterable
    {
        yield 'banking' => [BindingPreset::Banking];
        yield 'healthcare' => [BindingPreset::Healthcare];
        yield 'legal' => [BindingPreset::Legal];
    }

    #[Test]
    public function regulatedPresetReturnsUnauthorizedWhenNoIdentity(): void
    {
        $model = new stdClass();

        $middleware = $this->buildMiddleware(
            binderModels: ['user' => $model],
            config: new ModelBindingConfig(preset: BindingPreset::Banking),
            identityResolver: static fn() => null,
        );

        $matched = $this->createMatchedRoute(['user' => '42']);
        $request = $this->createRequestWithRoute($matched);
        $handler = $this->createHandlerReturning($this->nextResponse);

        $response = $this->dispatch($middleware, $request, $handler);

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function regulatedPresetReturnsUnauthorizedJsonWhenNoIdentityAndAcceptJson(): void
    {
        $model = new stdClass();

        $middleware = $this->buildMiddleware(
            binderModels: ['user' => $model],
            config: new ModelBindingConfig(preset: BindingPreset::Healthcare),
            identityResolver: static fn() => null,
        );

        $matched = $this->createMatchedRoute(['user' => '42']);
        $request = $this->createRequestWithRoute($matched, acceptJson: true);
        $handler = $this->createHandlerReturning($this->nextResponse);

        $response = $this->dispatch($middleware, $request, $handler);

        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function regulatedPresetAllowsWhenAuthHookReturnsTrue(): void
    {
        $model = new stdClass();
        $this->authHook->method('authorize')->willReturn(true);

        $identity = $this->createAuthenticatedIdentity('user-1');
        $middleware = $this->buildMiddleware(
            binderModels: ['user' => $model],
            config: new ModelBindingConfig(preset: BindingPreset::Banking),
            identityResolver: static fn() => $identity,
        );

        $matched = $this->createMatchedRoute(['user' => '42']);
        $request = $this->createRequestWithRoute($matched);
        $handler = $this->createHandlerReturning($this->nextResponse);

        $response = $this->dispatch($middleware, $request, $handler);

        self::assertSame($this->nextResponse, $response);
    }

    #[Test]
    public function regulatedPresetRefusesWhenAuthHookDenies(): void
    {
        $model = new stdClass();
        $this->authHook->method('authorize')->willReturn(false);

        $identity = $this->createAuthenticatedIdentity('user-1');
        $middleware = $this->buildMiddleware(
            binderModels: ['user' => $model],
            config: new ModelBindingConfig(preset: BindingPreset::Banking),
            identityResolver: static fn() => $identity,
        );

        $matched = $this->createMatchedRoute(['user' => '42']);
        $request = $this->createRequestWithRoute($matched);
        $handler = $this->createHandlerReturning($this->nextResponse);

        $response = $this->dispatch($middleware, $request, $handler);

        self::assertSame(404, $response->getStatusCode());
    }

    // ── PublicRoute attribute bypass on regulated preset ──────────────────

    #[Test]
    public function regulatedPresetAllowsBypassWhenPublicRouteAttributeOnMethod(): void
    {
        $model = new stdClass();

        $middleware = $this->buildMiddleware(
            binderModels: ['user' => $model],
            config: new ModelBindingConfig(preset: BindingPreset::Banking),
            identityResolver: static fn() => null,
            handler: [MiddlewareTestPublicMethodController::class, 'show'],
        );

        $matched = $this->createMatchedRoute(
            parameters: ['user' => '42'],
            attributes: ['_without_authorization' => true],
            handler: [MiddlewareTestPublicMethodController::class, 'show'],
        );
        $request = $this->createRequestWithRoute($matched);
        $handler = $this->createHandlerReturning($this->nextResponse);

        $response = $this->dispatch($middleware, $request, $handler);

        self::assertSame($this->nextResponse, $response);
    }

    #[Test]
    public function regulatedPresetAllowsBypassWhenPublicRouteAttributeOnClass(): void
    {
        $model = new stdClass();

        $middleware = $this->buildMiddleware(
            binderModels: ['user' => $model],
            config: new ModelBindingConfig(preset: BindingPreset::Legal),
            identityResolver: static fn() => null,
            handler: [MiddlewareTestPublicClassController::class, 'show'],
        );

        $matched = $this->createMatchedRoute(
            parameters: ['user' => '42'],
            attributes: ['_without_authorization' => true],
            handler: [MiddlewareTestPublicClassController::class, 'show'],
        );
        $request = $this->createRequestWithRoute($matched);
        $handler = $this->createHandlerReturning($this->nextResponse);

        $response = $this->dispatch($middleware, $request, $handler);

        self::assertSame($this->nextResponse, $response);
    }

    #[Test]
    public function publicRouteOnPermissivePresetSkipsAuthzWithBypassFlag(): void
    {
        $model = new stdClass();
        // Would deny if called, but bypass + public route skips it
        $this->authHook->method('authorize')->willReturn(false);

        $middleware = $this->buildMiddleware(
            binderModels: ['user' => $model],
            config: new ModelBindingConfig(preset: BindingPreset::Standard),
            identityResolver: fn() => $this->createAuthenticatedIdentity('u-1'),
            handler: [MiddlewareTestPublicMethodController::class, 'show'],
        );

        $matched = $this->createMatchedRoute(
            parameters: ['user' => '42'],
            attributes: ['_without_authorization' => true],
            handler: [MiddlewareTestPublicMethodController::class, 'show'],
        );
        $request = $this->createRequestWithRoute($matched);
        $handler = $this->createHandlerReturning($this->nextResponse);

        $response = $this->dispatch($middleware, $request, $handler);

        self::assertSame($this->nextResponse, $response);
    }

    // ── Tenant context ───────────────────────────────────────────────────

    #[Test]
    public function buildResolutionContextIncludesTenantId(): void
    {
        $tenant = new Tenant(id: 'tenant-abc', name: 'Acme Corp');
        $tenantContext = new TenantContext();
        $tenantContext->set($tenant);

        $capturedContext = null;
        $resolver = $this->createMock(ModelResolverPort::class);
        $resolver->expects(self::once())
            ->method('resolve')
            ->with(
                stdClass::class,
                'id',
                self::anything(),
                self::callback(static function (ResolutionContext $ctx) use (&$capturedContext): bool {
                    $capturedContext = $ctx;
                    return true;
                }),
            )
            ->willReturn(new stdClass());

        $this->authHook->method('authorize')->willReturn(true);

        $identity = $this->createAuthenticatedIdentity('user-1');
        $binder = $this->createRealBinder($resolver, ['user']);

        $middleware = new ModelBindingMiddleware(
            binder: $binder,
            config: new ModelBindingConfig(preset: BindingPreset::Standard),
            authHook: $this->authHook,
            tenantContext: $tenantContext,
            identityResolver: static fn() => $identity,
        );

        $matched = $this->createMatchedRoute(['user' => '42']);
        $request = $this->createRequestWithRoute($matched);
        $handler = $this->createHandlerReturning($this->nextResponse);

        $this->dispatch($middleware, $request, $handler);

        self::assertNotNull($capturedContext);
        self::assertSame('tenant-abc', $capturedContext->tenantId);
    }

    #[Test]
    public function buildResolutionContextHasNullTenantWhenNotResolved(): void
    {
        $tenantContext = new TenantContext();
        // Do not set any tenant

        $capturedContext = null;
        $resolver = $this->createMock(ModelResolverPort::class);
        $resolver->expects(self::once())
            ->method('resolve')
            ->with(
                self::anything(),
                self::anything(),
                self::anything(),
                self::callback(static function (ResolutionContext $ctx) use (&$capturedContext): bool {
                    $capturedContext = $ctx;
                    return true;
                }),
            )
            ->willReturn(new stdClass());

        $this->authHook->method('authorize')->willReturn(true);

        $identity = $this->createAuthenticatedIdentity('user-1');
        $binder = $this->createRealBinder($resolver, ['user']);

        $middleware = new ModelBindingMiddleware(
            binder: $binder,
            config: new ModelBindingConfig(preset: BindingPreset::Standard),
            authHook: $this->authHook,
            tenantContext: $tenantContext,
            identityResolver: static fn() => $identity,
        );

        $matched = $this->createMatchedRoute(['user' => '42']);
        $request = $this->createRequestWithRoute($matched);
        $handler = $this->createHandlerReturning($this->nextResponse);

        $this->dispatch($middleware, $request, $handler);

        self::assertNotNull($capturedContext);
        self::assertNull($capturedContext->tenantId);
    }

    #[Test]
    public function buildResolutionContextHasNullTenantWhenContextNotInjected(): void
    {
        $capturedContext = null;
        $resolver = $this->createMock(ModelResolverPort::class);
        $resolver->expects(self::once())
            ->method('resolve')
            ->with(
                self::anything(),
                self::anything(),
                self::anything(),
                self::callback(static function (ResolutionContext $ctx) use (&$capturedContext): bool {
                    $capturedContext = $ctx;
                    return true;
                }),
            )
            ->willReturn(new stdClass());

        $this->authHook->method('authorize')->willReturn(true);

        $identity = $this->createAuthenticatedIdentity('user-1');
        $binder = $this->createRealBinder($resolver, ['user']);

        $middleware = new ModelBindingMiddleware(
            binder: $binder,
            config: new ModelBindingConfig(preset: BindingPreset::Standard),
            authHook: $this->authHook,
            tenantContext: null,
            identityResolver: static fn() => $identity,
        );

        $matched = $this->createMatchedRoute(['user' => '42']);
        $request = $this->createRequestWithRoute($matched);
        $handler = $this->createHandlerReturning($this->nextResponse);

        $this->dispatch($middleware, $request, $handler);

        self::assertNotNull($capturedContext);
        self::assertNull($capturedContext->tenantId);
    }

    #[Test]
    public function buildResolutionContextIncludesSubjectId(): void
    {
        $capturedContext = null;
        $resolver = $this->createMock(ModelResolverPort::class);
        $resolver->expects(self::once())
            ->method('resolve')
            ->with(
                self::anything(),
                self::anything(),
                self::anything(),
                self::callback(static function (ResolutionContext $ctx) use (&$capturedContext): bool {
                    $capturedContext = $ctx;
                    return true;
                }),
            )
            ->willReturn(new stdClass());

        $this->authHook->method('authorize')->willReturn(true);

        $identity = $this->createAuthenticatedIdentity('subject-99');
        $binder = $this->createRealBinder($resolver, ['user']);

        $middleware = new ModelBindingMiddleware(
            binder: $binder,
            config: new ModelBindingConfig(preset: BindingPreset::Standard),
            authHook: $this->authHook,
            identityResolver: static fn() => $identity,
        );

        $matched = $this->createMatchedRoute(['user' => '42']);
        $request = $this->createRequestWithRoute($matched);
        $handler = $this->createHandlerReturning($this->nextResponse);

        $this->dispatch($middleware, $request, $handler);

        self::assertNotNull($capturedContext);
        self::assertSame('subject-99', $capturedContext->subjectId);
    }

    #[Test]
    public function buildResolutionContextHasNullSubjectIdWhenNoIdentity(): void
    {
        $capturedContext = null;
        $resolver = $this->createMock(ModelResolverPort::class);
        $resolver->expects(self::once())
            ->method('resolve')
            ->with(
                self::anything(),
                self::anything(),
                self::anything(),
                self::callback(static function (ResolutionContext $ctx) use (&$capturedContext): bool {
                    $capturedContext = $ctx;
                    return true;
                }),
            )
            ->willReturn(new stdClass());

        $this->authHook->method('authorize')->willReturn(true);

        $binder = $this->createRealBinder($resolver, ['user']);

        $middleware = new ModelBindingMiddleware(
            binder: $binder,
            config: new ModelBindingConfig(preset: BindingPreset::Standard),
            authHook: $this->authHook,
            identityResolver: null,
        );

        $matched = $this->createMatchedRoute(['user' => '42']);
        $request = $this->createRequestWithRoute($matched);
        $handler = $this->createHandlerReturning($this->nextResponse);

        $this->dispatch($middleware, $request, $handler);

        self::assertNotNull($capturedContext);
        self::assertNull($capturedContext->subjectId);
    }

    // ── Soft-deleted rows ────────────────────────────────────────────────

    #[Test]
    public function trashedRowsAreExcludedUnlessTheRouteAsksForThem(): void
    {
        $context = $this->captureResolutionContext($this->createMatchedRoute(['user' => '42']));

        self::assertFalse($context->includeTrashed);
    }

    #[Test]
    public function aRouteMayOptIntoResolvingTrashedRows(): void
    {
        // A restore or archive endpoint declares `_with_trashed` where it is
        // registered. Its neighbours on the same model do not, and stay blind
        // to deleted rows.
        $matched = $this->createMatchedRoute(['user' => '42'], attributes: ['_with_trashed' => true]);

        $context = $this->captureResolutionContext($matched);

        self::assertTrue($context->includeTrashed);
    }

    #[Test]
    public function nothingOnTheRequestCanOptIntoResolvingTrashedRows(): void
    {
        // The opt-in is read from the route table, never from the request, so a
        // caller cannot un-hide soft-deleted rows on a route that never granted
        // it — here by planting the same name as a request attribute.
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/users/42');

        $attributes = [
            '_route' => $this->createMatchedRoute(['user' => '42']),
            '_with_trashed' => true,
        ];

        $context = $this->captureResolutionContext(null, $this->buildRequestStub($attributes, false, $uri));

        self::assertFalse($context->includeTrashed);
    }

    /**
     * Run one binding and hand back the ResolutionContext the resolver saw.
     */
    private function captureResolutionContext(
        ?MatchedRoute $matchedRoute,
        ?ServerRequestInterface $request = null,
    ): ResolutionContext {
        $capturedContext = null;
        $resolver = $this->createMock(ModelResolverPort::class);
        $resolver->expects(self::once())
            ->method('resolve')
            ->with(
                self::anything(),
                self::anything(),
                self::anything(),
                self::callback(static function (ResolutionContext $ctx) use (&$capturedContext): bool {
                    $capturedContext = $ctx;
                    return true;
                }),
            )
            ->willReturn(new stdClass());

        $this->authHook->method('authorize')->willReturn(true);

        $middleware = new ModelBindingMiddleware(
            binder: $this->createRealBinder($resolver, ['user']),
            config: new ModelBindingConfig(preset: BindingPreset::Standard),
            authHook: $this->authHook,
        );

        $this->dispatch(
            $middleware,
            $request ?? $this->createRequestWithRoute($matchedRoute),
            $this->createHandlerReturning($this->nextResponse),
        );

        self::assertInstanceOf(ResolutionContext::class, $capturedContext);

        return $capturedContext;
    }

    // ── Audit logging ────────────────────────────────────────────────────

    #[Test]
    public function auditsAuthorizationDenialWhenAuditLoggerConfigured(): void
    {
        $model = new stdClass();
        $this->authHook->method('authorize')->willReturn(false);

        $identity = $this->createAuthenticatedIdentity('user-1');

        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                AuditEvent::Authorization,
                AuditOutcome::Denied,
                'user-1',
                'model_binding_authorization',
                '/users/42',
                self::callback(static fn(array $meta): bool => isset($meta['model']) && $meta['model'] === stdClass::class),
            );

        $middleware = $this->buildMiddleware(
            binderModels: ['user' => $model],
            config: new ModelBindingConfig(preset: BindingPreset::Standard),
            identityResolver: static fn() => $identity,
            auditLogger: $auditLogger,
        );

        $matched = $this->createMatchedRoute(['user' => '42']);
        $request = $this->createRequestWithRoute($matched);
        $handler = $this->createHandlerReturning($this->nextResponse);

        $response = $this->dispatch($middleware, $request, $handler);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function auditLoggingFailureDoesNotDisruptRequest(): void
    {
        // The failure must not disrupt the request AND must not disappear. With
        // no logger wired into this middleware it goes to the server error log,
        // the same last-resort channel the kernel uses; see
        // AnonymousDenialAuditTest for the rest of that property.
        $this->expectOutputRegex('/Audit write failed for a model-binding denial/');

        $model = new stdClass();
        $this->authHook->method('authorize')->willReturn(false);

        $identity = $this->createAuthenticatedIdentity('user-1');

        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('log')
            ->willThrowException(new JsonException('Audit write failed'));

        $middleware = $this->buildMiddleware(
            binderModels: ['user' => $model],
            config: new ModelBindingConfig(preset: BindingPreset::Standard),
            identityResolver: static fn() => $identity,
            auditLogger: $auditLogger,
        );

        $matched = $this->createMatchedRoute(['user' => '42']);
        $request = $this->createRequestWithRoute($matched);
        $handler = $this->createHandlerReturning($this->nextResponse);

        // Should not throw despite audit failure
        $response = $this->dispatch($middleware, $request, $handler);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function noAuditLogWhenAuditLoggerIsNull(): void
    {
        $model = new stdClass();
        $this->authHook->method('authorize')->willReturn(false);

        $identity = $this->createAuthenticatedIdentity('user-1');

        // No audit logger injected
        $middleware = $this->buildMiddleware(
            binderModels: ['user' => $model],
            config: new ModelBindingConfig(preset: BindingPreset::Standard),
            identityResolver: static fn() => $identity,
            auditLogger: null,
        );

        $matched = $this->createMatchedRoute(['user' => '42']);
        $request = $this->createRequestWithRoute($matched);
        $handler = $this->createHandlerReturning($this->nextResponse);

        // Should not throw
        $response = $this->dispatch($middleware, $request, $handler);

        self::assertSame(404, $response->getStatusCode());
    }

    // ── Handler resolution edge cases ────────────────────────────────────

    #[Test]
    public function closureHandlerProducesNoBindingsSoMiddlewareDelegates(): void
    {
        // Closure handlers cannot be reflected by ModelBinder, so bind() returns []
        // and the middleware delegates to the handler without any authorization check
        $resolver = $this->createStub(ModelResolverPort::class);
        $binder = new ModelBinder(
            defaultResolver: $resolver,
            bindingResolver: new BindingResolver(compiledMap: new CompiledBindingMap([])),
            container: $this->createStub(ContainerInterface::class),
        );

        $middleware = new ModelBindingMiddleware(
            binder: $binder,
            config: new ModelBindingConfig(preset: BindingPreset::Banking),
            authHook: $this->authHook,
            identityResolver: static fn() => null,
        );

        $route = new Route(
            [Method::GET],
            '/users/{user}',
            static fn() => null,
            'users.closure',
            attributes: ['_without_authorization' => true],
        );
        $matched = new MatchedRoute($route, ['user' => '42']);
        $request = $this->createRequestWithRoute($matched);
        $handler = $this->createHandlerReturning($this->nextResponse);

        $response = $this->dispatch($middleware, $request, $handler);

        // Closure handler => binder returns [] => middleware delegates immediately
        self::assertSame($this->nextResponse, $response);
    }

    #[Test]
    public function invokableControllerResolvesAsPublicRoute(): void
    {
        $model = new stdClass();

        $middleware = $this->buildMiddleware(
            binderModels: ['user' => $model],
            config: new ModelBindingConfig(preset: BindingPreset::Banking),
            identityResolver: static fn() => null,
            handler: MiddlewareTestPublicInvokableController::class,
        );

        $matched = $this->createMatchedRoute(
            parameters: ['user' => '42'],
            attributes: ['_without_authorization' => true],
            handler: MiddlewareTestPublicInvokableController::class,
        );
        $request = $this->createRequestWithRoute($matched);
        $handler = $this->createHandlerReturning($this->nextResponse);

        $response = $this->dispatch($middleware, $request, $handler);

        // Invokable controller with #[PublicRoute] on class => bypass allowed
        self::assertSame($this->nextResponse, $response);
    }

    #[Test]
    public function nonExistentClassHandlerProducesNoBindingsSoMiddlewareDelegates(): void
    {
        // Non-class string handlers cannot be reflected by ModelBinder, so bind() returns []
        $resolver = $this->createStub(ModelResolverPort::class);
        $binder = new ModelBinder(
            defaultResolver: $resolver,
            bindingResolver: new BindingResolver(compiledMap: new CompiledBindingMap([])),
            container: $this->createStub(ContainerInterface::class),
        );

        $middleware = new ModelBindingMiddleware(
            binder: $binder,
            config: new ModelBindingConfig(preset: BindingPreset::Banking),
            authHook: $this->authHook,
            identityResolver: static fn() => null,
        );

        // Intentionally passing a non-class string to test ModelBinder's
        // graceful handling of unreflectable handlers.
        /** @var class-string $fakeHandler */
        $fakeHandler = substr('not_a_class_name', 0);
        $route = new Route(
            [Method::GET],
            '/users/{user}',
            $fakeHandler,
            'users.nonexistent',
            attributes: ['_without_authorization' => true],
        );
        $matched = new MatchedRoute($route, ['user' => '42']);
        $request = $this->createRequestWithRoute($matched);
        $handler = $this->createHandlerReturning($this->nextResponse);

        $response = $this->dispatch($middleware, $request, $handler);

        // Non-class handler => binder returns [] => middleware delegates immediately
        self::assertSame($this->nextResponse, $response);
    }

    // ── Route name fallback in error messages ────────────────────────────

    #[Test]
    public function authBypassForbiddenUsesRoutePathWhenNameIsNull(): void
    {
        $model = new stdClass();

        $middleware = $this->buildMiddleware(
            binderModels: ['user' => $model],
            config: new ModelBindingConfig(preset: BindingPreset::Banking),
            identityResolver: fn() => $this->createAuthenticatedIdentity('u-1'),
        );

        // Route with null name
        $route = new Route(
            [Method::GET],
            '/users/{user}',
            [MiddlewareTestController::class, 'index'],
            name: null,
            attributes: ['_without_authorization' => true],
        );
        $matched = new MatchedRoute($route, ['user' => '42']);
        $request = $this->createRequestWithRoute($matched);
        $handler = $this->createHandlerReturning($this->nextResponse);

        $response = $this->dispatch($middleware, $request, $handler);

        self::assertSame(403, $response->getStatusCode());
    }

    // ── Multiple models — partial authorization ──────────────────────────

    #[Test]
    public function authorizationDeniedOnSecondModelRefusesTheRequest(): void
    {
        $user = new stdClass();
        $post = new stdClass();

        $callCount = 0;
        $authHook = $this->createStub(AuthorizationHookInterface::class);
        $authHook->method('authorize')
            ->willReturnCallback(static function () use (&$callCount): bool {
                $callCount++;
                // Allow first model, deny second
                return $callCount === 1;
            });

        $identity = $this->createAuthenticatedIdentity('user-1');

        // `/users/{user}/posts/{post}` binds the post through the user, so the
        // two models come back from resolve() and resolveScoped() in turn.
        $queue = [$user, $post];
        $nextModel = static function () use (&$queue): ?object {
            return array_shift($queue);
        };

        $resolver = $this->createStub(ModelResolverPort::class);
        $resolver->method('resolve')->willReturnCallback($nextModel);
        $resolver->method('resolveScoped')->willReturnCallback($nextModel);

        $binder = $this->createRealBinder($resolver, ['user', 'post'], 'users.posts.show', '/users/{user}/posts/{post}');

        $middleware = new ModelBindingMiddleware(
            binder: $binder,
            config: new ModelBindingConfig(preset: BindingPreset::Standard),
            authHook: $authHook,
            identityResolver: static fn() => $identity,
        );

        $route = new Route(
            [Method::GET],
            '/users/{user}/posts/{post}',
            [MiddlewareTestController::class, 'index'],
            'users.posts.show',
        );
        $matched = new MatchedRoute($route, ['user' => '1', 'post' => '5']);
        $request = $this->createRequestWithRoute($matched);
        $handler = $this->createHandlerReturning($this->nextResponse);

        $response = $this->dispatch($middleware, $request, $handler);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(2, $callCount);
    }

    // ── _without_authorization not set vs set to false ────────────────────

    #[Test]
    public function withoutAuthorizationAttributeNotSetMeansAuthzIsEnforced(): void
    {
        $model = new stdClass();
        $this->authHook->method('authorize')->willReturn(false);

        $identity = $this->createAuthenticatedIdentity('user-1');
        $middleware = $this->buildMiddleware(
            binderModels: ['user' => $model],
            config: new ModelBindingConfig(preset: BindingPreset::Standard),
            identityResolver: static fn() => $identity,
        );

        // No _without_authorization attribute set
        $matched = $this->createMatchedRoute(parameters: ['user' => '42'], attributes: []);
        $request = $this->createRequestWithRoute($matched);
        $handler = $this->createHandlerReturning($this->nextResponse);

        $response = $this->dispatch($middleware, $request, $handler);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function withoutAuthorizationSetToFalseMeansAuthzIsEnforced(): void
    {
        $model = new stdClass();
        $this->authHook->method('authorize')->willReturn(false);

        $identity = $this->createAuthenticatedIdentity('user-1');
        $middleware = $this->buildMiddleware(
            binderModels: ['user' => $model],
            config: new ModelBindingConfig(preset: BindingPreset::Standard),
            identityResolver: static fn() => $identity,
        );

        $matched = $this->createMatchedRoute(
            parameters: ['user' => '42'],
            attributes: ['_without_authorization' => false],
        );
        $request = $this->createRequestWithRoute($matched);
        $handler = $this->createHandlerReturning($this->nextResponse);

        $response = $this->dispatch($middleware, $request, $handler);

        self::assertSame(404, $response->getStatusCode());
    }

    // ── Combined tenant + identity context ───────────────────────────────

    #[Test]
    public function fullContextWithTenantAndIdentity(): void
    {
        $tenant = new Tenant(id: 'org-42', name: 'Org42');
        $tenantContext = new TenantContext();
        $tenantContext->set($tenant);

        $capturedContext = null;
        $resolver = $this->createMock(ModelResolverPort::class);
        $resolver->expects(self::once())
            ->method('resolve')
            ->with(
                self::anything(),
                self::anything(),
                self::anything(),
                self::callback(static function (ResolutionContext $ctx) use (&$capturedContext): bool {
                    $capturedContext = $ctx;
                    return true;
                }),
            )
            ->willReturn(new stdClass());

        $this->authHook->method('authorize')->willReturn(true);

        $identity = $this->createAuthenticatedIdentity('user-77');
        $binder = $this->createRealBinder($resolver, ['user']);

        $middleware = new ModelBindingMiddleware(
            binder: $binder,
            config: new ModelBindingConfig(preset: BindingPreset::Standard),
            authHook: $this->authHook,
            tenantContext: $tenantContext,
            identityResolver: static fn() => $identity,
        );

        $matched = $this->createMatchedRoute(['user' => '42']);
        $request = $this->createRequestWithRoute($matched);
        $handler = $this->createHandlerReturning($this->nextResponse);

        $this->dispatch($middleware, $request, $handler);

        self::assertNotNull($capturedContext);
        self::assertSame('org-42', $capturedContext->tenantId);
        self::assertSame('user-77', $capturedContext->subjectId);
    }

    // ── Error response content negotiation ───────────────────────────────

    #[Test]
    public function aPolicyRefusalShowsTheNotFoundMessageInPlainText(): void
    {
        // A policy refusal is answered with what a missing row is answered with,
        // body included: "Forbidden" in a body is the existence oracle the status
        // code used to be.
        $model = new stdClass();
        $this->authHook->method('authorize')->willReturn(false);

        $identity = $this->createAuthenticatedIdentity('user-1');
        $middleware = $this->buildMiddleware(
            binderModels: ['user' => $model],
            config: new ModelBindingConfig(preset: BindingPreset::Standard),
            identityResolver: static fn() => $identity,
        );

        $matched = $this->createMatchedRoute(['user' => '42']);
        $request = $this->createRequestWithRoute($matched, acceptJson: false);
        $handler = $this->createHandlerReturning($this->nextResponse);

        $response = $this->dispatch($middleware, $request, $handler);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('Not Found', (string) $response->getBody());
    }

    #[Test]
    public function aPolicyRefusalShowsJsonWhenAcceptHeaderPresent(): void
    {
        $model = new stdClass();
        $this->authHook->method('authorize')->willReturn(false);

        $identity = $this->createAuthenticatedIdentity('user-1');
        $middleware = $this->buildMiddleware(
            binderModels: ['user' => $model],
            config: new ModelBindingConfig(preset: BindingPreset::Standard),
            identityResolver: static fn() => $identity,
        );

        $matched = $this->createMatchedRoute(['user' => '42']);
        $request = $this->createRequestWithRoute($matched, acceptJson: true);
        $handler = $this->createHandlerReturning($this->nextResponse);

        $response = $this->dispatch($middleware, $request, $handler);

        self::assertSame(404, $response->getStatusCode());
        $body = (string) $response->getBody();
        /** @var array{error: string, status: int} $decoded */
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Not Found', $decoded['error']);
        self::assertSame(404, $decoded['status']);
    }

    #[Test]
    public function unauthorizedResponseShowsJsonWhenAcceptHeaderPresent(): void
    {
        $model = new stdClass();

        $middleware = $this->buildMiddleware(
            binderModels: ['user' => $model],
            config: new ModelBindingConfig(preset: BindingPreset::Banking),
            identityResolver: static fn() => null,
        );

        $matched = $this->createMatchedRoute(['user' => '42']);
        $request = $this->createRequestWithRoute($matched, acceptJson: true);
        $handler = $this->createHandlerReturning($this->nextResponse);

        $response = $this->dispatch($middleware, $request, $handler);

        self::assertSame(401, $response->getStatusCode());
        $body = (string) $response->getBody();
        /** @var array{error: string, status: int} $decoded */
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Unauthorized', $decoded['error']);
        self::assertSame(401, $decoded['status']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Build a ModelBindingMiddleware with a binder that returns the given models.
     *
     * When $binderModels is null, the resolver returns null causing a ModelBindingException (404).
     * When $binderModels is an empty array, the binder returns no models.
     * When $binderModels is a non-empty array, the binder returns those models.
     *
     * @param array<string, object>|null $binderModels
     * @param (Closure(): ?IdentityInterface)|null $identityResolver
     * @param callable|class-string|array{0: class-string, 1: string}|null $handler
     * @param list<string>|null $parameterNames
     */
    private function buildMiddleware(
        ?array $binderModels,
        ?ModelBindingConfig $config = null,
        ?TenantContext $tenantContext = null,
        ?Closure $identityResolver = null,
        ?LoggerInterface $logger = null,
        ?AuditLoggerInterface $auditLogger = null,
        mixed $handler = null,
        string $routeName = 'users.show',
        string $routePath = '/users/{user}',
        ?array $parameterNames = null,
    ): ModelBindingMiddleware {
        $handler ??= [MiddlewareTestController::class, 'index'];

        if ($binderModels === null) {
            // Resolver returns null => binder throws modelNotFound
            $resolver = $this->createStub(ModelResolverPort::class);
            $resolver->method('resolve')->willReturn(null);
            $paramNames = $parameterNames ?? ['user'];
        } elseif ($binderModels === []) {
            // No models => binder returns empty array (no bindings in compiled map)
            $resolver = $this->createStub(ModelResolverPort::class);
            $paramNames = [];
        } else {
            // Return the provided models in order, whichever door the binder
            // comes through: a nested route resolves its first parameter with
            // resolve() and every parameter under it with resolveScoped(), so
            // a stub that only answers resolve() would 404 the child.
            $resolver = $this->createStub(ModelResolverPort::class);
            $queue = array_values($binderModels);
            $nextModel = static function () use (&$queue): ?object {
                return array_shift($queue);
            };
            $resolver->method('resolve')->willReturnCallback($nextModel);
            $resolver->method('resolveScoped')->willReturnCallback($nextModel);
            /** @var list<string> $derivedNames */
            $derivedNames = array_keys($binderModels);
            $paramNames = $parameterNames ?? $derivedNames;
        }

        $binder = $this->createRealBinder($resolver, $paramNames, $routeName, $routePath);

        return new ModelBindingMiddleware(
            binder: $binder,
            config: $config ?? new ModelBindingConfig(preset: BindingPreset::Standard),
            authHook: $this->authHook,
            tenantContext: $tenantContext,
            identityResolver: $identityResolver,
            logger: $logger,
            auditLogger: $auditLogger,
        );
    }

    /**
     * Create a real ModelBinder backed by a CompiledBindingMap with given parameters.
     *
     * @param list<string> $parameterNames
     */
    private function createRealBinder(
        ModelResolverPort $resolver,
        array $parameterNames,
        string $routeName = 'users.show',
        string $routePath = '/users/{user}',
    ): ModelBinder {
        $map = [];
        if ($parameterNames !== []) {
            $bindings = [];
            foreach ($parameterNames as $name) {
                $bindings[$name] = new BindingMeta(class: stdClass::class, keyName: 'id', keyType: 'string');
            }
            $map[$routeName] = $bindings;
        }

        $bindingResolver = new BindingResolver(compiledMap: new CompiledBindingMap($map));

        return new ModelBinder(
            defaultResolver: $resolver,
            bindingResolver: $bindingResolver,
            container: $this->createStub(ContainerInterface::class),
        );
    }

    /**
     * Create a matched route with given parameters and optional attributes.
     *
     * @param array<string, string> $parameters
     * @param array<string, mixed> $attributes
     * @param callable|class-string|array{0: class-string, 1: string}|null $handler
     */
    private function createMatchedRoute(
        array $parameters,
        array $attributes = [],
        mixed $handler = null,
    ): MatchedRoute {
        $handler ??= [MiddlewareTestController::class, 'index'];

        $route = new Route(
            methods: [Method::GET],
            path: '/users/{user}',
            handler: $handler,
            name: 'users.show',
            attributes: $attributes,
        );

        return new MatchedRoute($route, $parameters);
    }

    /**
     * Run the middleware the way the kernel runs it.
     *
     * The middleware does not read `_route`: the kernel passes the route it is
     * dispatching to the pipeline, which binds a per-dispatch copy of the
     * middleware to it. The request builders here write the same route into both
     * places, which is what a real dispatch does; that the two cannot be made to
     * DISAGREE is the subject of {@see DispatchedRouteAuthorityTest}.
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

    /**
     * Create a PSR-7 request stub with a matched route attribute.
     */
    private function createRequestWithRoute(
        ?MatchedRoute $matchedRoute,
        bool $acceptJson = false,
    ): ServerRequestInterface {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/users/42');

        $attributes = ['_route' => $matchedRoute];

        return $this->buildRequestStub($attributes, $acceptJson, $uri);
    }

    /**
     * Build a recursive request stub that properly tracks withAttribute() calls.
     *
     * @param array<string, mixed> $attributes
     */
    private function buildRequestStub(
        array &$attributes,
        bool $acceptJson,
        UriInterface $uri,
    ): ServerRequestInterface {
        $request = $this->createStub(ServerRequestInterface::class);

        $request->method('getAttribute')
            ->willReturnCallback(static fn(string $name) => $attributes[$name] ?? null);

        $request->method('getHeaderLine')
            ->willReturnCallback(static fn(string $name): string => match ($name) {
                'Accept' => $acceptJson ? 'application/json' : 'text/html',
                default => '',
            });

        $request->method('getUri')->willReturn($uri);

        $request->method('withAttribute')
            ->willReturnCallback(function (string $name, mixed $value) use (&$attributes, $acceptJson, $uri): ServerRequestInterface {
                $attributes[$name] = $value;
                return $this->buildRequestStub($attributes, $acceptJson, $uri);
            });

        return $request;
    }

    /**
     * Create a handler that returns the given response.
     */
    private function createHandlerReturning(ResponseInterface $response): RequestHandlerInterface
    {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);
        return $handler;
    }

    /**
     * Create a handler that captures the request passed to it.
     */
    private function createCapturingHandler(?ServerRequestInterface &$capturedRequest): RequestHandlerInterface
    {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')
            ->willReturnCallback(function (ServerRequestInterface $req) use (&$capturedRequest): ResponseInterface {
                $capturedRequest = $req;
                return $this->nextResponse;
            });
        return $handler;
    }

    /**
     * Create an authenticated identity stub.
     */
    private function createAuthenticatedIdentity(string $id): IdentityInterface
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('id')->willReturn($id);
        $identity->method('isAuthenticated')->willReturn(true);
        return $identity;
    }

}

// ── Test controller stubs ────────────────────────────────────────────────

/**
 * Plain controller with no #[PublicRoute] attribute.
 */
final class MiddlewareTestController
{
    public function index(stdClass $user): void {}
}

/**
 * Controller whose method parameter name does not match any route parameter,
 * so the binder produces no bindings via implicit resolution.
 */
final class MiddlewareTestNoBindingController
{
    public function index(string $name = ''): void {}
}

/**
 * Controller with #[PublicRoute] on a method.
 */
final class MiddlewareTestPublicMethodController
{
    #[PublicRoute(reason: 'Testing public route method attribute')]
    public function show(stdClass $user): void {}
}

/**
 * Controller class with #[PublicRoute] on the class itself.
 */
#[PublicRoute(reason: 'Testing public route class attribute')]
final class MiddlewareTestPublicClassController
{
    public function show(stdClass $user): void {}
}

/**
 * Invokable controller with #[PublicRoute] on the class.
 */
#[PublicRoute(reason: 'Testing invokable public route')]
final class MiddlewareTestPublicInvokableController
{
    public function __invoke(stdClass $user): void {}
}
