<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Audit\NullAuditLogger;
use Pulsar\Container\Container;
use Pulsar\Container\ContainerInterface;
use Pulsar\Core\Controller\ReflectionControllerResolver;
use Pulsar\Extension\Auth\AuthExtension;
use Pulsar\Extension\Auth\AuthServiceProvider;
use Pulsar\Extension\Auth\Http\Controller\OAuth2Controller;
use Pulsar\Extension\Auth\Http\Controller\OidcDiscoveryController;
use Pulsar\Extension\Auth\Http\Controller\UserInfoController;
use Pulsar\Extension\Auth\OAuth2\Contract\AccessTokenRepositoryInterface;
use Pulsar\Extension\Auth\OAuth2\Contract\UserClaimsProviderInterface;
use Pulsar\Extension\Auth\OAuth2\Token\AccessToken;
use Pulsar\Http\Factory\ResponseFactory;
use Pulsar\Http\Factory\StreamFactory;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Method;
use Pulsar\Routing\Router;
use Pulsar\Routing\RoutingException;
use Pulsar\Security\Crypto\KeyRingInterface;

use function array_map;
use function class_exists;
use function count;
use function implode;
use function is_array;
use function is_string;
use function json_decode;
use function method_exists;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * The routing half of these tests exists because the extension used to pass the
 * route *name* in the handler position — `$router->get('/authorize',
 * 'oauth2.authorize')` — which the kernel cannot invoke, so every one of the
 * fourteen endpoints threw `RoutingException::nonCallableHandler()` at dispatch.
 * The test that was supposed to cover it asserted only that `group()` and
 * `get()` had been called on a mock router, which stays green no matter what is
 * registered. So nothing here asserts that a route was registered: every route
 * is resolved through the container and invoked, and the response is checked
 * for something only the real handler could have produced.
 */
#[CoversClass(AuthExtension::class)]
#[CoversClass(OAuth2Controller::class)]
#[CoversClass(OidcDiscoveryController::class)]
#[CoversClass(UserInfoController::class)]
final class AuthExtensionTest extends TestCase
{
    #[Test]
    public function name_returns_pulsar_auth(): void
    {
        $extension = new AuthExtension();

        self::assertSame('pulsar/auth', $extension->name());
    }

    #[Test]
    public function providers_returns_auth_service_provider(): void
    {
        $extension = new AuthExtension();

        $providers = $extension->providers();

        self::assertCount(1, $providers);
        self::assertSame(AuthServiceProvider::class, $providers[0]);
    }

    #[Test]
    public function register_does_not_throw(): void
    {
        $extension = new AuthExtension();
        /** @var ContainerInterface&Stub $container */
        $container = $this->createStub(ContainerInterface::class);

        $extension->register($container);

        // No exception means success
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function boot_registers_exactly_the_endpoints_the_extension_can_serve(): void
    {
        $router = $this->bootedRouter($this->container());

        $registered = array_map(
            static fn($route): string => implode('|', array_map(
                static fn($method): string => $method->value,
                $route->methods,
            )) . ' ' . $route->path,
            $router->routes(),
        );

        self::assertSame([
            'GET|HEAD /oauth/authorize',
            'POST /oauth/token',
            'POST /oauth/introspect',
            'POST /oauth/revoke',
            'GET|HEAD /.well-known/openid-configuration',
            'GET|HEAD /.well-known/jwks.json',
        ], $registered);
    }

    /**
     * The regression pin: a handler the kernel cannot invoke must not reach the
     * router. `Kernel::invokeHandler()` accepts a callable, a `[class, method]`
     * pair or an invokable class-string and throws for anything else, so every
     * registered handler is checked against that contract here.
     */
    #[Test]
    public function every_registered_route_carries_a_handler_the_kernel_can_invoke(): void
    {
        $container = $this->container(withClaimsProvider: true);
        $router = $this->bootedRouter($container);
        $resolver = new ReflectionControllerResolver($container);

        self::assertCount(7, $router->routes());

        foreach ($router->routes() as $route) {
            /** @var mixed $handler */
            $handler = $route->handler;

            self::assertTrue(
                is_array($handler) && count($handler) === 2,
                sprintf('Route %s must carry a [class, method] handler.', $route->path),
            );
            /** @var array{0: mixed, 1: mixed} $handler */
            self::assertTrue(is_string($handler[0]) && is_string($handler[1]));
            /** @var array{0: string, 1: string} $handler */
            [$class, $method] = $handler;

            self::assertTrue(class_exists($class), sprintf('%s: %s is not a class.', $route->path, $class));
            self::assertTrue(
                method_exists($class, $method),
                sprintf('%s: %s::%s() does not exist.', $route->path, $class, $method),
            );

            // Resolution is the other half: a class that exists but cannot be
            // built from the container fails at dispatch just as loudly.
            self::assertInstanceOf($class, $resolver->resolve($class));
        }
    }

    #[Test]
    public function every_registered_route_carries_its_name_in_the_name_position(): void
    {
        $container = $this->container(withClaimsProvider: true);
        $router = $this->bootedRouter($container);

        $names = [];
        foreach ($router->routes() as $route) {
            $names[$route->path] = $route->name;
        }

        self::assertSame([
            '/oauth/authorize' => 'oauth2.authorize',
            '/oauth/token' => 'oauth2.token',
            '/oauth/introspect' => 'oauth2.introspect',
            '/oauth/revoke' => 'oauth2.revoke',
            '/.well-known/openid-configuration' => 'oauth2.oidc.discovery',
            '/.well-known/jwks.json' => 'oauth2.oidc.jwks',
            '/oauth/userinfo' => 'oauth2.oidc.userinfo',
        ], $names);
    }

    #[Test]
    public function token_endpoint_reaches_the_authorization_server(): void
    {
        $container = $this->container();
        $matched = $this->bootedRouter($container)->match(Method::POST, '/oauth/token');

        self::assertSame([OAuth2Controller::class, 'token'], $matched->getHandler());

        $response = $this->oauth2Controller($container)
            ->token(new ServerRequest(method: 'POST', uri: '/oauth/token'));

        // RFC 6749 §5.2: a token request with no grant_type is invalid_request.
        // Only the authorization server produces that body.
        self::assertSame(400, $response->getStatusCode());
        self::assertSame(
            ['error' => 'invalid_request', 'error_description' => 'Missing required parameter: grant_type'],
            $this->decode((string) $response->getBody()),
        );
    }

    #[Test]
    public function authorize_endpoint_reaches_the_authorization_server(): void
    {
        $container = $this->container();
        $matched = $this->bootedRouter($container)->match(Method::GET, '/oauth/authorize');

        self::assertSame([OAuth2Controller::class, 'authorize'], $matched->getHandler());

        $response = $this->oauth2Controller($container)
            ->authorize(new ServerRequest(method: 'GET', uri: '/oauth/authorize'));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(
            ['error' => 'invalid_request', 'error_description' => 'Missing required parameter: client_id'],
            $this->decode((string) $response->getBody()),
        );
    }

    #[Test]
    public function introspection_and_revocation_endpoints_reach_the_authorization_server(): void
    {
        $container = $this->container();
        $router = $this->bootedRouter($container);
        $controller = $this->oauth2Controller($container);

        self::assertSame(
            [OAuth2Controller::class, 'introspect'],
            $router->match(Method::POST, '/oauth/introspect')->getHandler(),
        );
        self::assertSame(
            [OAuth2Controller::class, 'revoke'],
            $router->match(Method::POST, '/oauth/revoke')->getHandler(),
        );

        // Both endpoints authenticate the client first, so an anonymous call is
        // rejected as invalid_client rather than reaching the token store.
        $introspection = $controller->introspect(new ServerRequest(method: 'POST', uri: '/oauth/introspect'));
        self::assertSame(401, $introspection->getStatusCode());

        $revocation = $controller->revoke(new ServerRequest(method: 'POST', uri: '/oauth/revoke'));
        self::assertSame(401, $revocation->getStatusCode());
    }

    #[Test]
    public function discovery_endpoint_serves_the_openid_provider_document(): void
    {
        $container = $this->container();
        $matched = $this->bootedRouter($container)->match(Method::GET, '/.well-known/openid-configuration');

        self::assertSame([OidcDiscoveryController::class, 'configuration'], $matched->getHandler());

        $response = $this->oidcController($container)->configuration();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));

        $document = $this->decode((string) $response->getBody());
        self::assertArrayHasKey('jwks_uri', $document);
        self::assertSame(['code'], $document['response_types_supported']);
        self::assertSame(['S256'], $document['code_challenge_methods_supported']);
    }

    #[Test]
    public function jwks_endpoint_serves_a_key_set(): void
    {
        $container = $this->container();
        $matched = $this->bootedRouter($container)->match(Method::GET, '/.well-known/jwks.json');

        self::assertSame([OidcDiscoveryController::class, 'jwks'], $matched->getHandler());

        $response = $this->oidcController($container)->jwks();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['keys' => []], $this->decode((string) $response->getBody()));
    }

    #[Test]
    public function userinfo_is_not_routed_without_an_application_claims_provider(): void
    {
        $router = $this->bootedRouter($this->container());

        $this->expectException(RoutingException::class);

        $router->match(Method::GET, '/oauth/userinfo');
    }

    #[Test]
    public function userinfo_is_routed_once_the_application_binds_a_claims_provider(): void
    {
        $container = $this->container(withClaimsProvider: true);
        $matched = $this->bootedRouter($container)->match(Method::GET, '/oauth/userinfo');

        self::assertSame([UserInfoController::class, '__invoke'], $matched->getHandler());

        $tokenValue = 'aVerySpecificReferenceTokenValue';
        /** @var AccessTokenRepositoryInterface $tokens */
        $tokens = $container->get(AccessTokenRepositoryInterface::class);
        $tokens->persist(new AccessToken(
            id: 'tok-1',
            clientId: 'client-1',
            subjectId: 'subject-1',
            scopes: ['openid', 'profile'],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
            tokenValue: $tokenValue,
        ));

        $response = $this->userInfoController($container)(new ServerRequest(
            method: 'GET',
            uri: '/oauth/userinfo',
            headers: ['Authorization' => 'Bearer ' . $tokenValue],
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['name' => 'Test Subject', 'sub' => 'subject-1'],
            $this->decode((string) $response->getBody()),
        );
    }

    #[Test]
    public function userinfo_refuses_a_request_without_a_usable_bearer_token(): void
    {
        $container = $this->container(withClaimsProvider: true);
        $controller = $this->userInfoController($container);

        $anonymous = $controller(new ServerRequest(method: 'GET', uri: '/oauth/userinfo'));
        self::assertSame(401, $anonymous->getStatusCode());
        self::assertSame('Bearer', $anonymous->getHeaderLine('WWW-Authenticate'));

        $unknownToken = $controller(new ServerRequest(
            method: 'GET',
            uri: '/oauth/userinfo',
            headers: ['Authorization' => 'Bearer neverIssued'],
        ));
        self::assertSame(401, $unknownToken->getStatusCode());
        self::assertStringContainsString('error="invalid_token"', $unknownToken->getHeaderLine('WWW-Authenticate'));
        self::assertSame(['error' => 'invalid_token'], $this->decode((string) $unknownToken->getBody()));
    }

    /**
     * The seven WebAuthn ceremony paths were registered against handler names
     * that did not resolve, so they answered 500 rather than performing a
     * ceremony. They are now absent: each one needs a server-side challenge
     * bound to the caller's session and an acting-user identity, neither of
     * which the extension owns.
     */
    #[Test]
    public function webauthn_ceremony_paths_are_not_registered(): void
    {
        $router = $this->bootedRouter($this->container(withClaimsProvider: true));

        $paths = [
            [Method::POST, '/webauthn/register/options'],
            [Method::POST, '/webauthn/register/verify'],
            [Method::POST, '/webauthn/authenticate/options'],
            [Method::POST, '/webauthn/authenticate/verify'],
            [Method::GET, '/webauthn/authenticators'],
            [Method::PUT, '/webauthn/authenticators/abc/rename'],
            [Method::DELETE, '/webauthn/authenticators/abc'],
        ];

        foreach ($paths as [$method, $path]) {
            try {
                $router->match($method, $path);
                self::fail(sprintf('%s %s should not be registered.', $method->value, $path));
            } catch (RoutingException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function bootedRouter(Container $container): Router
    {
        $router = new Router();
        new AuthExtension()->boot($container, $router);

        return $router;
    }

    /**
     * A real container with the framework services the OAuth2 stack needs, so
     * the controllers resolve exactly as they do at dispatch.
     */
    private function container(bool $withClaimsProvider = false): Container
    {
        $container = new Container();
        $container->instance(ResponseFactoryInterface::class, new ResponseFactory());
        $container->instance(StreamFactoryInterface::class, new StreamFactory());
        $container->instance(AuditLoggerInterface::class, new NullAuditLogger());
        $container->instance(KeyRingInterface::class, new class implements KeyRingInterface {
            public function keyFor(string $kid): ?string
            {
                return null;
            }

            public function all(): iterable
            {
                return [];
            }
        });

        if ($withClaimsProvider) {
            $container->instance(UserClaimsProviderInterface::class, new class implements UserClaimsProviderInterface {
                public function getClaims(string $subjectId, array $scopes): array
                {
                    return ['name' => 'Test Subject'];
                }

                public function getSubjectIdentifier(string $subjectId, string $clientId): string
                {
                    return $subjectId;
                }
            });
        }

        new AuthServiceProvider()->register($container);

        return $container;
    }

    private function oauth2Controller(Container $container): OAuth2Controller
    {
        $controller = new ReflectionControllerResolver($container)->resolve(OAuth2Controller::class);
        self::assertInstanceOf(OAuth2Controller::class, $controller);

        return $controller;
    }

    private function oidcController(Container $container): OidcDiscoveryController
    {
        $controller = new ReflectionControllerResolver($container)->resolve(OidcDiscoveryController::class);
        self::assertInstanceOf(OidcDiscoveryController::class, $controller);

        return $controller;
    }

    private function userInfoController(Container $container): UserInfoController
    {
        $controller = new ReflectionControllerResolver($container)->resolve(UserInfoController::class);
        self::assertInstanceOf(UserInfoController::class, $controller);

        return $controller;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
