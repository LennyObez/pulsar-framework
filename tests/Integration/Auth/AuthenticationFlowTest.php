<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Auth\AuthenticationState;
use Pulsar\Auth\AuthManager;
use Pulsar\Auth\Authorization\Gate;
use Pulsar\Auth\Authorization\InMemoryRoleRegistry;
use Pulsar\Auth\Guard\SessionGuard;
use Pulsar\Auth\Guard\TokenGuard;
use Pulsar\Auth\Guard\TokenResolverInterface;
use Pulsar\Auth\Identity\AnonymousIdentity;
use Pulsar\Auth\Identity\Identity;
use Pulsar\Auth\Identity\TwoFactorStatus;
use Pulsar\Auth\Middleware\AuthenticationMiddleware;
use Pulsar\Auth\Middleware\AuthorizationMiddleware;
use Pulsar\Auth\SecurityContext;
use Pulsar\Core\Kernel;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Method;
use Pulsar\Http\ResponseStatus;
use Pulsar\Observability\Metrics\LabelSet;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Routing\Route;
use Pulsar\Security\Audit\AuditFileSink;
use Pulsar\Security\Audit\AuditLogger;
use Pulsar\Security\Session\SessionInterface;

use function bin2hex;
use function is_file;
use function random_bytes;
use function sys_get_temp_dir;
use function unlink;

#[CoversClass(AuthManager::class)]
#[CoversClass(SessionGuard::class)]
#[CoversClass(TokenGuard::class)]
#[CoversClass(AuthenticationMiddleware::class)]
#[CoversClass(AuthorizationMiddleware::class)]
#[CoversClass(SecurityContext::class)]
final class AuthenticationFlowTest extends TestCase
{
    /**
     * The credential the resolver refuses. Held in a constant so the test can
     * assert it never reaches the audit record: ASVS 7.2.1 requires the
     * decision to be logged and the token not to be.
     */
    private const string REJECTED_TOKEN = 'aa1f4c7e9b2d6083c5e1a7f30d9b46c2';

    private ?string $auditLogPath = null;

    protected function tearDown(): void
    {
        if ($this->auditLogPath !== null && is_file($this->auditLogPath)) {
            unlink($this->auditLogPath);
        }

        $this->auditLogPath = null;
    }

    #[Test]
    public function sessionLoginAndAuthenticateRoundtrip(): void
    {
        $sessionData = [];
        $session = $this->createStub(SessionInterface::class);
        $session->method('isStarted')->willReturn(true);
        $session->method('regenerate');
        $session->method('set')->willReturnCallback(
            function (string $key, mixed $value) use (&$sessionData): void {
                $sessionData[$key] = $value;
            },
        );
        $session->method('has')->willReturnCallback(
            function (string $key) use (&$sessionData): bool {
                return isset($sessionData[$key]);
            },
        );
        $session->method('get')->willReturnCallback(
            function (string $key, mixed $default = null) use (&$sessionData): mixed {
                return $sessionData[$key] ?? $default;
            },
        );

        $guard = new SessionGuard($session);
        $manager = new AuthManager();
        $manager->addGuard($guard);

        $identity = new Identity(
            id: 'user-42',
            displayName: 'Jane Doe',
            roles: ['admin'],
            twoFactorStatus: TwoFactorStatus::Disabled,
            attributes: ['email' => 'jane@example.com'],
        );

        // Login stores identity in session
        $guard->login($identity);

        // Authenticate retrieves it
        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: [],
        );

        $result = $manager->authenticate($request);

        self::assertInstanceOf(Identity::class, $result);
        self::assertSame('user-42', $result->id());
        self::assertSame('Jane Doe', $result->displayName());
        self::assertSame(['admin'], $result->roles());
        self::assertSame('jane@example.com', $result->attribute('email'));
    }

    #[Test]
    public function tokenGuardAuthenticatesFromBearerHeader(): void
    {
        $expectedIdentity = new Identity(
            id: 'api-user-1',
            displayName: 'API User',
            roles: ['api'],
        );

        $resolver = $this->createStub(TokenResolverInterface::class);
        $resolver->method('resolve')
            ->willReturn($expectedIdentity);

        $guard = new TokenGuard($resolver);
        $manager = new AuthManager();
        $manager->addGuard($guard);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/api/data',
            headers: ['Authorization' => 'Bearer valid-api-token'],
        );

        $result = $manager->authenticate($request);

        self::assertSame($expectedIdentity, $result);
    }

    #[Test]
    public function authenticationMiddlewareSetsLazyContextThatResolvesOnDemand(): void
    {
        $expectedIdentity = new Identity(
            id: 'user-1',
            displayName: 'Test',
            roles: ['user'],
        );

        $session = $this->createStub(SessionInterface::class);
        $session->method('isStarted')->willReturn(true);
        $session->method('has')->willReturn(true);
        $session->method('get')->willReturn($expectedIdentity->toArray());

        $guard = new SessionGuard($session);
        $manager = new AuthManager();
        $manager->addGuard($guard);

        $middleware = new AuthenticationMiddleware($manager, new AuthenticationState());

        $request = new ServerRequest(
            method: 'GET',
            uri: '/dashboard',
            headers: [],
        );

        /** @var ServerRequestInterface|null $capturedRequest */
        $capturedRequest = null;
        $handler = new class ($capturedRequest) implements RequestHandlerInterface {
            public ?ServerRequestInterface $captured;

            public function __construct(?ServerRequestInterface &$captured)
            {
                $this->captured = &$captured;
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->captured = $request;
                return new Response(statusCode: ResponseStatus::OK->value, body: 'OK');
            }
        };

        $middleware->process($request, $handler);

        self::assertNotNull($capturedRequest);

        // Default identity is anonymous
        $defaultIdentity = $capturedRequest->getAttribute('_identity');
        self::assertInstanceOf(AnonymousIdentity::class, $defaultIdentity);

        // SecurityContext resolves to real identity on access
        /** @var SecurityContext $context */
        $context = $capturedRequest->getAttribute('_security_context');
        self::assertInstanceOf(SecurityContext::class, $context);

        $resolved = $context->identity();
        self::assertSame('user-1', $resolved->id());
        self::assertTrue($context->isAuthenticated());
    }

    #[Test]
    public function multiGuardFallbackSessionThenToken(): void
    {
        $tokenIdentity = new Identity(
            id: 'token-user',
            displayName: 'Token User',
            roles: ['api'],
        );

        // Session guard returns null (no session data)
        $session = $this->createStub(SessionInterface::class);
        $session->method('isStarted')->willReturn(true);
        $session->method('has')->willReturn(false);

        $sessionGuard = new SessionGuard($session);

        // Token guard resolves the token
        $resolver = $this->createStub(TokenResolverInterface::class);
        $resolver->method('resolve')
            ->willReturn($tokenIdentity);

        $tokenGuard = new TokenGuard($resolver);

        $manager = new AuthManager();
        $manager->addGuard($sessionGuard);
        $manager->addGuard($tokenGuard);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/api/resource',
            headers: ['Authorization' => 'Bearer my-token'],
        );

        $result = $manager->authenticate($request);

        self::assertSame('token-user', $result->id());
    }

    #[Test]
    public function logoutClearsSessionIdentity(): void
    {
        $sessionData = [];
        $session = $this->createStub(SessionInterface::class);
        $session->method('isStarted')->willReturn(true);
        $session->method('regenerate');
        $session->method('set')->willReturnCallback(
            function (string $key, mixed $value) use (&$sessionData): void {
                $sessionData[$key] = $value;
            },
        );
        $session->method('remove')->willReturnCallback(
            function (string $key) use (&$sessionData): void {
                unset($sessionData[$key]);
            },
        );
        $session->method('has')->willReturnCallback(
            function (string $key) use (&$sessionData): bool {
                return isset($sessionData[$key]);
            },
        );
        $session->method('get')->willReturnCallback(
            function (string $key, mixed $default = null) use (&$sessionData): mixed {
                return $sessionData[$key] ?? $default;
            },
        );

        $guard = new SessionGuard($session);
        $manager = new AuthManager();
        $manager->addGuard($guard);

        $identity = new Identity(
            id: 'user-1',
            displayName: 'Test',
            roles: ['user'],
        );

        $guard->login($identity);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: [],
        );

        // Before logout
        $result = $manager->authenticate($request);
        self::assertSame('user-1', $result->id());

        // After logout
        $guard->logout();
        $result = $manager->authenticate($request);
        self::assertInstanceOf(AnonymousIdentity::class, $result);
    }

    /**
     * A refusal with no identity behind it is COUNTED, not chained.
     *
     * This test used to assert the opposite, and the posture changed for a
     * reason the shape of the old entry states by itself: actor `anonymous`,
     * action `authenticate`, reason `unauthenticated`, and `resource` set to the
     * REQUESTED PATH. Every such entry carries the same information as every
     * other one, none of them names anybody, and the only column that varies is
     * chosen by the caller — so an unauthenticated client could grow the
     * tamper-evident chain without limit, one HMAC advance and one `LOCK_EX`
     * append per request, and bury the denials that do name an actor.
     *
     * What is recorded instead is a counter labelled with the ROUTE and the
     * reason, both bounded by the route table, plus the occurrence in the access
     * log every deployment already keeps. Nothing about the credential reaches
     * either, which is the half of ASVS 7.2.1 this test still asserts on disk.
     *
     * A denial that DOES name an actor is unchanged — full entry, one per
     * occurrence, no ceiling. {@see \Pulsar\Tests\Integration\Auth\AuthorizationFlowTest::deniedPermissionIsRecordedInTheAuditSink()}
     * is that half, driven through the same kernel.
     */
    #[Test]
    public function aRejectedBearerTokenIsCountedRatherThanChained(): void
    {
        $auditPath = $this->createAuditLogPath();
        $auditLogger = new AuditLogger(new AuditFileSink($auditPath), random_bytes(32));

        // A token is presented and refused: an authentication decision,
        // not a missing credential.
        $resolver = $this->createStub(TokenResolverInterface::class);
        $resolver->method('resolve')->willReturn(null);

        $manager = new AuthManager();
        $manager->addGuard(new TokenGuard($resolver));

        // Wired the way AuthWiring wires it: AuthenticationMiddleware global,
        // AuthorizationMiddleware behind the `auth` route alias.
        $metrics = new MetricRegistry();

        $kernel = new Kernel();
        $kernel->addMiddleware(new AuthenticationMiddleware($manager, new AuthenticationState()));
        $kernel->middlewareRegistry()->alias(
            'auth',
            new AuthorizationMiddleware(new Gate(new InMemoryRoleRegistry()), $auditLogger, null, $metrics),
        );
        $kernel->router()->add(new Route(
            methods: [Method::GET],
            path: '/reports/quarterly',
            handler: static fn(): Response => Response::text('quarterly numbers'),
            attributes: ['permissions' => ['reports.view']],
            middleware: ['auth'],
        ));

        $response = $kernel->handle(new ServerRequest(
            method: 'GET',
            uri: '/reports/quarterly',
            headers: ['Authorization' => 'Bearer ' . self::REJECTED_TOKEN],
        ));

        self::assertSame(ResponseStatus::Unauthorized->value, $response->getStatusCode());

        self::assertFileDoesNotExist(
            $auditPath,
            'an unauthenticated refusal must not advance the tamper-evident chain: '
            . 'the entry names nobody and its only varying column is the caller\'s own path.',
        );

        self::assertSame(
            1.0,
            $metrics->counter('pulsar_auth_anonymous_denials_total')->value(
                new LabelSet(['reason' => 'unauthenticated', 'route' => '/reports/quarterly']),
            ),
            'the refusal is counted, labelled with the route rather than the requested path',
        );

        // Ten more of the same, each to a path of the caller's choosing. The
        // chain does not move and the counter has one series, which is the
        // property the per-path chain entry could not have.
        for ($i = 0; $i < 10; ++$i) {
            $kernel->handle(new ServerRequest(
                method: 'GET',
                uri: '/reports/quarterly?probe=' . $i,
                headers: ['Authorization' => 'Bearer ' . self::REJECTED_TOKEN],
            ));
        }

        self::assertFileDoesNotExist($auditPath);
        self::assertCount(
            1,
            $metrics->counter('pulsar_auth_anonymous_denials_total')->values(),
            'the label set is route-table-bounded, so eleven caller-chosen paths are one series',
        );
        self::assertSame(
            11.0,
            $metrics->counter('pulsar_auth_anonymous_denials_total')->value(
                new LabelSet(['reason' => 'unauthenticated', 'route' => '/reports/quarterly']),
            ),
        );
    }

    private function createAuditLogPath(): string
    {
        return $this->auditLogPath = sys_get_temp_dir()
            . '/pulsar_authn_flow_audit_' . bin2hex(random_bytes(8)) . '.jsonl';
    }
}
