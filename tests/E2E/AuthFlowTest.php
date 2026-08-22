<?php

declare(strict_types=1);

namespace Pulsar\Tests\E2E;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Auth\AuthManagerInterface;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Authorization\PolicyContext;
use Pulsar\Auth\Guard\GuardInterface;
use Pulsar\Auth\Identity\AnonymousIdentity;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Auth\Identity\TwoFactorStatus;
use Pulsar\Auth\Middleware\AuthenticationMiddleware;
use Pulsar\Auth\Middleware\AuthorizationMiddleware;
use Pulsar\Auth\SecurityContext;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Method;
use Pulsar\Http\ResponseStatus;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Routing\Route;

use function in_array;

/**
 * End-to-end tests for the authentication and authorization flow.
 *
 * Simulates the full middleware pipeline: AuthenticationMiddleware attaches
 * a SecurityContext, AuthorizationMiddleware resolves identity and enforces
 * access control. Uses in-memory test doubles for the AuthManager and Gate.
 */
#[CoversClass(AuthenticationMiddleware::class)]
#[CoversClass(AuthorizationMiddleware::class)]
#[CoversClass(SecurityContext::class)]
#[CoversClass(AnonymousIdentity::class)]
final class AuthFlowTest extends TestCase
{
    /** @psalm-suppress PropertyNotSetInConstructor -- initialized in setUp() */
    private AuthFlowTestSessionStore $sessionStore;

    protected function setUp(): void
    {
        $this->sessionStore = new AuthFlowTestSessionStore();
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    private function createRequest(
        string $method = 'GET',
        string $path = '/',
        array $headers = [],
    ): ServerRequest {
        return new ServerRequest(
            method: $method,
            uri: $path,
            headers: $headers,
        );
    }

    /**
     * Run a request through the auth middleware pipeline.
     *
     * The router is what puts `_route` on the request in production, so the harness
     * has to supply it too: AuthorizationMiddleware fails closed without it, and a
     * pipeline test that omits it would be measuring the absence of routing rather
     * than the authorization decision. Pass `null` to exercise that fail-closed
     * path deliberately.
     *
     * @param list<string>|null $permissions Route-declared permissions, or null for no route context
     */
    private function processRequest(
        ServerRequestInterface $request,
        AuthManagerInterface $authManager,
        GateInterface $gate,
        RequestHandlerInterface $handler,
        ?array $permissions = ['_authenticated'],
    ): ResponseInterface {
        if ($permissions !== null) {
            $request = $request->withAttribute('_route', $this->matchedRoute($request, $permissions));
        }

        $authMiddleware = new AuthenticationMiddleware($authManager);
        $authzMiddleware = new AuthorizationMiddleware($gate);

        // Pipeline: authentication -> authorization -> handler
        $authzHandler = new class ($authzMiddleware, $handler) implements RequestHandlerInterface {
            public function __construct(
                private readonly AuthorizationMiddleware $middleware,
                private readonly RequestHandlerInterface $inner,
            ) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->middleware->process($request, $this->inner);
            }
        };

        return $authMiddleware->process($request, $authzHandler);
    }

    /**
     * Build the route the router would have matched for this request.
     *
     * @param list<string> $permissions
     */
    private function matchedRoute(ServerRequestInterface $request, array $permissions): MatchedRoute
    {
        return new MatchedRoute(
            new Route(
                methods: [Method::from($request->getMethod())],
                path: $request->getUri()->getPath(),
                handler: static fn(): ResponseInterface => Response::text(''),
                attributes: ['permissions' => $permissions],
            ),
        );
    }

    private function createHandler(callable $fn): RequestHandlerInterface
    {
        return new class ($fn) implements RequestHandlerInterface {
            /** @param callable(ServerRequestInterface): ResponseInterface $fn */
            public function __construct(private readonly mixed $fn) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return ($this->fn)($request);
            }
        };
    }

    #[Test]
    public function unauthenticatedRequestReturns401(): void
    {
        $authManager = new AuthFlowTestAuthManager($this->sessionStore);
        $gate = new AuthFlowTestGate();

        $request = $this->createRequest('GET', '/dashboard');

        $response = $this->processRequest(
            $request,
            $authManager,
            $gate,
            $this->createHandler(fn(ServerRequestInterface $req): ResponseInterface => Response::text('dashboard content')),
        );

        self::assertSame(ResponseStatus::Unauthorized->value, $response->getStatusCode());
    }

    #[Test]
    public function loginStoresSessionAndSubsequentRequestSucceeds(): void
    {
        $authManager = new AuthFlowTestAuthManager($this->sessionStore);
        $gate = new AuthFlowTestGate();

        // Simulate login: store identity in session
        $this->sessionStore->login('user-123', 'Alice');

        $request = $this->createRequest('GET', '/dashboard');

        $response = $this->processRequest(
            $request,
            $authManager,
            $gate,
            $this->createHandler(fn(ServerRequestInterface $req): ResponseInterface => Response::text('dashboard content')),
        );

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
        self::assertSame('dashboard content', (string) $response->getBody());
    }

    #[Test]
    public function authenticatedRequestReturns200(): void
    {
        $authManager = new AuthFlowTestAuthManager($this->sessionStore);
        $gate = new AuthFlowTestGate();

        // Login first
        $this->sessionStore->login('user-456', 'Bob');

        $request = $this->createRequest('GET', '/profile');

        $response = $this->processRequest(
            $request,
            $authManager,
            $gate,
            $this->createHandler(function (ServerRequestInterface $req): ResponseInterface {
                /** @var SecurityContext|null $ctx */
                $ctx = $req->getAttribute('_security_context');
                $identity = $ctx?->identity();

                return Response::json([
                    'authenticated' => $identity?->isAuthenticated(),
                    'user_id' => $identity?->id(),
                    'name' => $identity?->displayName(),
                ]);
            }),
        );

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());

        /** @var array{authenticated: bool, user_id: string, name: string} $data */
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['authenticated']);
        self::assertSame('user-456', $data['user_id']);
        self::assertSame('Bob', $data['name']);
    }

    #[Test]
    public function logoutClearsSessionState(): void
    {
        $authManager = new AuthFlowTestAuthManager($this->sessionStore);
        $gate = new AuthFlowTestGate();

        // Login
        $this->sessionStore->login('user-789', 'Charlie');

        // Verify authenticated
        $authedResponse = $this->processRequest(
            $this->createRequest('GET', '/dashboard'),
            $authManager,
            $gate,
            $this->createHandler(fn(ServerRequestInterface $req): ResponseInterface => Response::text('ok')),
        );
        self::assertSame(ResponseStatus::OK->value, $authedResponse->getStatusCode());

        // Logout
        $this->sessionStore->logout();

        // Verify unauthenticated after logout
        $loggedOutResponse = $this->processRequest(
            $this->createRequest('GET', '/dashboard'),
            $authManager,
            $gate,
            $this->createHandler(fn(ServerRequestInterface $req): ResponseInterface => Response::text('should not reach')),
        );
        self::assertSame(ResponseStatus::Unauthorized->value, $loggedOutResponse->getStatusCode());
    }

    #[Test]
    public function securityContextIsAttachedToRequestAndContainsIdentity(): void
    {
        $authManager = new AuthFlowTestAuthManager($this->sessionStore);
        $gate = new AuthFlowTestGate();

        $this->sessionStore->login('user-ctx', 'ContextUser');

        $capturedContext = null;

        $this->processRequest(
            $this->createRequest('GET', '/api/me'),
            $authManager,
            $gate,
            $this->createHandler(function (ServerRequestInterface $req) use (&$capturedContext): ResponseInterface {
                $capturedContext = $req->getAttribute('_security_context');
                return Response::text('ok');
            }),
        );

        self::assertInstanceOf(SecurityContext::class, $capturedContext);
        self::assertSame('user-ctx', $capturedContext->identity()->id());
        self::assertSame('ContextUser', $capturedContext->identity()->displayName());
        self::assertTrue($capturedContext->identity()->isAuthenticated());
    }

    #[Test]
    public function multipleUsersInSequenceHaveIsolatedSessions(): void
    {
        $authManager = new AuthFlowTestAuthManager($this->sessionStore);
        $gate = new AuthFlowTestGate();

        // Alice's request
        $this->sessionStore->login('alice-01', 'Alice');
        $aliceId = null;

        $this->processRequest(
            $this->createRequest('GET', '/profile'),
            $authManager,
            $gate,
            $this->createHandler(function (ServerRequestInterface $req) use (&$aliceId): ResponseInterface {
                /** @var SecurityContext|null $ctx */
                $ctx = $req->getAttribute('_security_context');
                $aliceId = $ctx?->identity()->id();
                return Response::text('ok');
            }),
        );

        // Switch to Bob's session
        $this->sessionStore->logout();
        $this->sessionStore->login('bob-02', 'Bob');
        $bobId = null;

        $this->processRequest(
            $this->createRequest('GET', '/profile'),
            $authManager,
            $gate,
            $this->createHandler(function (ServerRequestInterface $req) use (&$bobId): ResponseInterface {
                /** @var SecurityContext|null $ctx */
                $ctx = $req->getAttribute('_security_context');
                $bobId = $ctx?->identity()->id();
                return Response::text('ok');
            }),
        );

        self::assertSame('alice-01', $aliceId, 'Alice should see her own session');
        self::assertSame('bob-02', $bobId, 'Bob should see his own session');
        self::assertNotSame($aliceId, $bobId, 'Sessions must not bleed across users');
    }

    #[Test]
    public function reRequestAfterLogoutReturns401(): void
    {
        $authManager = new AuthFlowTestAuthManager($this->sessionStore);
        $gate = new AuthFlowTestGate();

        // Full cycle: login -> verify -> logout -> verify 401
        $this->sessionStore->login('user-999', 'Dana');

        $firstResponse = $this->processRequest(
            $this->createRequest('GET', '/secure'),
            $authManager,
            $gate,
            $this->createHandler(fn(ServerRequestInterface $req): ResponseInterface => Response::text('secure data')),
        );
        self::assertSame(ResponseStatus::OK->value, $firstResponse->getStatusCode());

        // Logout
        $this->sessionStore->logout();

        // Re-request should be 401
        $secondResponse = $this->processRequest(
            $this->createRequest('GET', '/secure'),
            $authManager,
            $gate,
            $this->createHandler(fn(ServerRequestInterface $req): ResponseInterface => Response::text('should not reach')),
        );
        self::assertSame(ResponseStatus::Unauthorized->value, $secondResponse->getStatusCode());
    }

    /**
     * Without route context the required permissions are unknown, so an
     * authenticated request must not pass unchecked.
     */
    #[Test]
    public function authenticatedRequestWithoutRouteContextIsForbidden(): void
    {
        $this->sessionStore->login('user-noroute', 'Erin');
        $reached = false;

        $response = $this->processRequest(
            $this->createRequest('GET', '/dashboard'),
            new AuthFlowTestAuthManager($this->sessionStore),
            new AuthFlowTestGate(),
            $this->createHandler(function () use (&$reached): ResponseInterface {
                $reached = true;

                return Response::text('should not reach');
            }),
            permissions: null,
        );

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
        self::assertFalse($reached, 'The handler must not run when authorization cannot be decided');
    }

    /**
     * A route behind the auth middleware that declares no permission default-denies
     * rather than admitting every authenticated user. Naming `_authenticated` is the
     * operator's explicit opt-in to "any logged-in identity may pass".
     */
    #[Test]
    public function routeDeclaringNoPermissionIsForbiddenEvenWhenAuthenticated(): void
    {
        $this->sessionStore->login('user-nodecl', 'Frank');

        $response = $this->processRequest(
            $this->createRequest('GET', '/dashboard'),
            new AuthFlowTestAuthManager($this->sessionStore),
            new AuthFlowTestGate(),
            $this->createHandler(fn(): ResponseInterface => Response::text('should not reach')),
            permissions: [],
        );

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
    }

    #[Test]
    public function declaredPermissionIsCheckedAgainstTheGate(): void
    {
        $this->sessionStore->login('user-rbac', 'Grace');
        $authManager = new AuthFlowTestAuthManager($this->sessionStore);
        $handler = $this->createHandler(fn(): ResponseInterface => Response::text('reports'));

        $granted = $this->processRequest(
            $this->createRequest('GET', '/reports'),
            $authManager,
            new AuthFlowTestGate(['reports.view']),
            $handler,
            permissions: ['reports.view'],
        );

        self::assertSame(ResponseStatus::OK->value, $granted->getStatusCode());

        $denied = $this->processRequest(
            $this->createRequest('GET', '/reports'),
            $authManager,
            new AuthFlowTestGate(['reports.view']),
            $handler,
            permissions: ['reports.delete'],
        );

        self::assertSame(ResponseStatus::Forbidden->value, $denied->getStatusCode());
    }
}

/**
 * Minimal in-memory session store for auth flow testing.
 */
final class AuthFlowTestSessionStore
{
    private ?string $userId = null;
    private ?string $displayName = null;

    public function login(string $userId, string $displayName): void
    {
        $this->userId = $userId;
        $this->displayName = $displayName;
    }

    public function logout(): void
    {
        $this->userId = null;
        $this->displayName = null;
    }

    public function isLoggedIn(): bool
    {
        return $this->userId !== null;
    }

    public function getUserId(): ?string
    {
        return $this->userId;
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }
}

/**
 * Test AuthManager that resolves identity from the in-memory session store.
 */
final class AuthFlowTestAuthManager implements AuthManagerInterface
{
    public function __construct(
        private readonly AuthFlowTestSessionStore $session,
    ) {}

    #[Override]
    public function authenticate(ServerRequestInterface $request): IdentityInterface
    {
        if ($this->session->isLoggedIn()) {
            return new AuthFlowTestIdentity(
                id: $this->session->getUserId() ?? '',
                displayName: $this->session->getDisplayName() ?? '',
            );
        }

        return new AnonymousIdentity();
    }

    #[Override]
    public function guard(string $name): GuardInterface
    {
        return new AuthFlowTestGuard($this->session);
    }

    #[Override]
    public function defaultGuard(): string
    {
        return 'session';
    }
}

/**
 * Test identity representing an authenticated user.
 */
final readonly class AuthFlowTestIdentity implements IdentityInterface
{
    public function __construct(
        private string $id,
        private string $displayName,
    ) {}

    #[Override]
    public function id(): string
    {
        return $this->id;
    }

    #[Override]
    public function displayName(): string
    {
        return $this->displayName;
    }

    #[Override]
    public function roles(): array
    {
        return ['user'];
    }

    #[Override]
    public function hasRole(string $role): bool
    {
        return $role === 'user';
    }

    #[Override]
    public function twoFactorStatus(): TwoFactorStatus
    {
        return TwoFactorStatus::Disabled;
    }

    #[Override]
    public function isAuthenticated(): bool
    {
        return true;
    }

    #[Override]
    public function attributes(): array
    {
        return [];
    }

    #[Override]
    public function attribute(string $key, mixed $default = null): mixed
    {
        return $default;
    }
}

/**
 * Test guard that authenticates via the session store.
 */
final readonly class AuthFlowTestGuard implements GuardInterface
{
    public function __construct(
        private AuthFlowTestSessionStore $session,
    ) {}

    #[Override]
    public function authenticate(ServerRequestInterface $request): ?IdentityInterface
    {
        if ($this->session->isLoggedIn()) {
            return new AuthFlowTestIdentity(
                id: $this->session->getUserId() ?? '',
                displayName: $this->session->getDisplayName() ?? '',
            );
        }

        return null;
    }

    #[Override]
    public function name(): string
    {
        return 'session';
    }
}

/**
 * Test gate that allows all authenticated users, or only a named set of permissions.
 */
final class AuthFlowTestGate implements GateInterface
{
    /**
     * @param list<string>|null $granted Permissions this gate allows, or null for "any, once authenticated"
     */
    public function __construct(
        private readonly ?array $granted = null,
    ) {}

    #[Override]
    public function allows(IdentityInterface $identity, string $permission, ?PolicyContext $context = null): bool
    {
        if (!$identity->isAuthenticated()) {
            return false;
        }

        return $this->granted === null || in_array($permission, $this->granted, true);
    }

    #[Override]
    public function denies(IdentityInterface $identity, string $permission, ?PolicyContext $context = null): bool
    {
        return !$this->allows($identity, $permission, $context);
    }
}
