<?php

declare(strict_types=1);

namespace Pulsar\Tests\E2E;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
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
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;

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

    private function createRequest(
        Method $method = Method::GET,
        string $path = '/',
        HeaderBag $headers = new HeaderBag(),
    ): Request {
        return new Request(
            method: $method,
            uri: $path,
            path: $path,
            queryString: '',
            headers: $headers,
            body: '',
        );
    }

    /**
     * Run a request through the auth middleware pipeline.
     *
     * @param callable(Request): Response $handler The final request handler
     */
    private function processRequest(
        Request $request,
        AuthManagerInterface $authManager,
        GateInterface $gate,
        callable $handler,
    ): Response {
        $authMiddleware = new AuthenticationMiddleware($authManager);
        $authzMiddleware = new AuthorizationMiddleware($gate);

        // Pipeline: authentication -> authorization -> handler
        return $authMiddleware->process(
            $request,
            fn(Request $req): Response => $authzMiddleware->process($req, $handler),
        );
    }

    #[Test]
    public function unauthenticatedRequestReturns401(): void
    {
        $authManager = new AuthFlowTestAuthManager($this->sessionStore);
        $gate = new AuthFlowTestGate();

        $request = $this->createRequest(Method::GET, '/dashboard');

        $response = $this->processRequest(
            $request,
            $authManager,
            $gate,
            fn(Request $req): Response => Response::text('dashboard content'),
        );

        self::assertSame(ResponseStatus::Unauthorized, $response->status);
    }

    #[Test]
    public function loginStoresSessionAndSubsequentRequestSucceeds(): void
    {
        $authManager = new AuthFlowTestAuthManager($this->sessionStore);
        $gate = new AuthFlowTestGate();

        // Simulate login: store identity in session
        $this->sessionStore->login('user-123', 'Alice');

        $request = $this->createRequest(Method::GET, '/dashboard');

        $response = $this->processRequest(
            $request,
            $authManager,
            $gate,
            fn(Request $req): Response => Response::text('dashboard content'),
        );

        self::assertSame(ResponseStatus::OK, $response->status);
        self::assertSame('dashboard content', $response->body);
    }

    #[Test]
    public function authenticatedRequestReturns200(): void
    {
        $authManager = new AuthFlowTestAuthManager($this->sessionStore);
        $gate = new AuthFlowTestGate();

        // Login first
        $this->sessionStore->login('user-456', 'Bob');

        $request = $this->createRequest(Method::GET, '/profile');

        $response = $this->processRequest(
            $request,
            $authManager,
            $gate,
            function (Request $req): Response {
                /** @var SecurityContext|null $ctx */
                $ctx = $req->attribute('_security_context');
                $identity = $ctx?->identity();

                return Response::json([
                    'authenticated' => $identity?->isAuthenticated(),
                    'user_id' => $identity?->id(),
                    'name' => $identity?->displayName(),
                ]);
            },
        );

        self::assertSame(ResponseStatus::OK, $response->status);

        /** @var array{authenticated: bool, user_id: string, name: string} $data */
        $data = json_decode($response->body, true);
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
            $this->createRequest(Method::GET, '/dashboard'),
            $authManager,
            $gate,
            fn(Request $req): Response => Response::text('ok'),
        );
        self::assertSame(ResponseStatus::OK, $authedResponse->status);

        // Logout
        $this->sessionStore->logout();

        // Verify unauthenticated after logout
        $loggedOutResponse = $this->processRequest(
            $this->createRequest(Method::GET, '/dashboard'),
            $authManager,
            $gate,
            fn(Request $req): Response => Response::text('should not reach'),
        );
        self::assertSame(ResponseStatus::Unauthorized, $loggedOutResponse->status);
    }

    #[Test]
    public function reRequestAfterLogoutReturns401(): void
    {
        $authManager = new AuthFlowTestAuthManager($this->sessionStore);
        $gate = new AuthFlowTestGate();

        // Full cycle: login -> verify -> logout -> verify 401
        $this->sessionStore->login('user-999', 'Dana');

        $firstResponse = $this->processRequest(
            $this->createRequest(Method::GET, '/secure'),
            $authManager,
            $gate,
            fn(Request $req): Response => Response::text('secure data'),
        );
        self::assertSame(ResponseStatus::OK, $firstResponse->status);

        // Logout
        $this->sessionStore->logout();

        // Re-request should be 401
        $secondResponse = $this->processRequest(
            $this->createRequest(Method::GET, '/secure'),
            $authManager,
            $gate,
            fn(Request $req): Response => Response::text('should not reach'),
        );
        self::assertSame(ResponseStatus::Unauthorized, $secondResponse->status);
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
 * Test gate that allows all authenticated users.
 */
final class AuthFlowTestGate implements GateInterface
{
    #[Override]
    public function allows(IdentityInterface $identity, string $permission, ?PolicyContext $context = null): bool
    {
        return $identity->isAuthenticated();
    }

    #[Override]
    public function denies(IdentityInterface $identity, string $permission, ?PolicyContext $context = null): bool
    {
        return !$this->allows($identity, $permission, $context);
    }
}
