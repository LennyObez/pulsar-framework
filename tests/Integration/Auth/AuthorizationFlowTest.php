<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Auth\AuthManagerInterface;
use Pulsar\Auth\Authorization\Gate;
use Pulsar\Auth\Authorization\InMemoryRoleRegistry;
use Pulsar\Auth\Authorization\Permission;
use Pulsar\Auth\Authorization\PolicyContext;
use Pulsar\Auth\Authorization\PolicyInterface;
use Pulsar\Auth\Authorization\Role;
use Pulsar\Auth\Identity\AnonymousIdentity;
use Pulsar\Auth\Identity\Identity;
use Pulsar\Auth\Middleware\AuthenticationMiddleware;
use Pulsar\Auth\Middleware\AuthorizationMiddleware;
use Pulsar\Auth\SecurityContext;
use Pulsar\Core\Kernel;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Method;
use Pulsar\Http\ResponseStatus;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Routing\Route;
use Pulsar\Security\Audit\AuditFileSink;
use Pulsar\Security\Audit\AuditLogger;

use function bin2hex;
use function explode;
use function file_get_contents;
use function is_file;
use function json_decode;
use function random_bytes;
use function sys_get_temp_dir;
use function trim;
use function unlink;

use const JSON_THROW_ON_ERROR;

#[CoversClass(Gate::class)]
#[CoversClass(InMemoryRoleRegistry::class)]
#[CoversClass(AuthenticationMiddleware::class)]
#[CoversClass(AuthorizationMiddleware::class)]
final class AuthorizationFlowTest extends TestCase
{
    private InMemoryRoleRegistry $registry;
    private Gate $gate;
    private ?string $auditLogPath = null;

    protected function setUp(): void
    {
        $this->registry = new InMemoryRoleRegistry();

        $this->registry->register(new Role(
            name: 'admin',
            permissions: [new Permission('*')],
        ));

        $this->registry->register(new Role(
            name: 'editor',
            permissions: [
                new Permission('content.view'),
                new Permission('content.create'),
                new Permission('content.edit'),
            ],
        ));

        $this->registry->register(new Role(
            name: 'viewer',
            permissions: [new Permission('content.view')],
        ));

        $this->gate = new Gate($this->registry, superRoles: ['superadmin']);
    }

    protected function tearDown(): void
    {
        if ($this->auditLogPath !== null && is_file($this->auditLogPath)) {
            unlink($this->auditLogPath);
        }

        $this->auditLogPath = null;
    }

    #[Test]
    public function adminRoleGrantsAllPermissions(): void
    {
        $identity = new Identity(id: 'admin-1', displayName: 'Admin', roles: ['admin']);

        self::assertTrue($this->gate->allows($identity, 'content.view'));
        self::assertTrue($this->gate->allows($identity, 'content.create'));
        self::assertTrue($this->gate->allows($identity, 'users.delete'));
        self::assertTrue($this->gate->allows($identity, 'system.shutdown'));
    }

    #[Test]
    public function editorRoleIsLimitedToContentPermissions(): void
    {
        $identity = new Identity(id: 'editor-1', displayName: 'Editor', roles: ['editor']);

        self::assertTrue($this->gate->allows($identity, 'content.view'));
        self::assertTrue($this->gate->allows($identity, 'content.create'));
        self::assertTrue($this->gate->allows($identity, 'content.edit'));
        self::assertFalse($this->gate->allows($identity, 'content.delete'));
        self::assertFalse($this->gate->allows($identity, 'users.view'));
    }

    #[Test]
    public function multipleRolesMergePermissions(): void
    {
        $identity = new Identity(id: 'multi-1', displayName: 'Multi-role', roles: ['editor', 'viewer']);

        // Editor + viewer permissions
        self::assertTrue($this->gate->allows($identity, 'content.view'));
        self::assertTrue($this->gate->allows($identity, 'content.create'));
        self::assertTrue($this->gate->allows($identity, 'content.edit'));

        // Still no access to ungranted permissions
        self::assertFalse($this->gate->allows($identity, 'users.delete'));
    }

    #[Test]
    public function superRoleBypassesAllChecks(): void
    {
        $identity = new Identity(id: 'super-1', displayName: 'Super', roles: ['superadmin']);

        // No permissions defined for superadmin role, but super-role bypasses
        self::assertTrue($this->gate->allows($identity, 'anything'));
        self::assertTrue($this->gate->allows($identity, 'users.delete'));
    }

    #[Test]
    public function abacPolicyOverridesRbac(): void
    {
        // Editor normally has content.edit permission
        $identity = new Identity(id: 'editor-1', displayName: 'Editor', roles: ['editor']);

        // Policy denies content.edit for this specific context
        $policy = $this->createStub(PolicyInterface::class);
        $policy->method('evaluate')
            ->willReturnCallback(static function ($identity, PolicyContext $context): ?bool {
                if ($context->permission === 'content.edit' && $context->resource === '/locked-article') {
                    return false;
                }
                return null;
            });

        $this->gate->addPolicy($policy);

        // Normal content.edit is allowed
        self::assertTrue($this->gate->allows(
            $identity,
            'content.edit',
            new PolicyContext(permission: 'content.edit', resource: '/normal-article'),
        ));

        // Locked article is denied by policy despite RBAC allowing it
        self::assertFalse($this->gate->allows(
            $identity,
            'content.edit',
            new PolicyContext(permission: 'content.edit', resource: '/locked-article'),
        ));
    }

    #[Test]
    public function authorizationMiddlewareBlocksUnauthenticatedRequests(): void
    {
        $middleware = new AuthorizationMiddleware($this->gate);

        $authManager = $this->createStub(AuthManagerInterface::class);
        $authManager->method('authenticate')->willReturn(new AnonymousIdentity());

        $request = $this->createRequestWithRoute(
            '/admin/users',
            ['permissions' => ['users.view']],
            $authManager,
        );

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(statusCode: ResponseStatus::OK->value, body: 'OK');
            }
        };

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::Unauthorized->value, $response->getStatusCode());
    }

    #[Test]
    public function authorizationMiddlewareAllowsAuthorizedRequests(): void
    {
        $middleware = new AuthorizationMiddleware($this->gate);

        $identity = new Identity(id: 'editor-1', displayName: 'Editor', roles: ['editor']);
        $authManager = $this->createStub(AuthManagerInterface::class);
        $authManager->method('authenticate')->willReturn($identity);

        $request = $this->createRequestWithRoute(
            '/content/articles',
            ['permissions' => ['content.view']],
            $authManager,
        );

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(statusCode: ResponseStatus::OK->value, body: 'OK');
            }
        };

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
    }

    #[Test]
    public function authorizationMiddlewareDeniesUnauthorizedRequests(): void
    {
        $middleware = new AuthorizationMiddleware($this->gate);

        $identity = new Identity(id: 'viewer-1', displayName: 'Viewer', roles: ['viewer']);
        $authManager = $this->createStub(AuthManagerInterface::class);
        $authManager->method('authenticate')->willReturn($identity);

        $request = $this->createRequestWithRoute(
            '/content/create',
            ['permissions' => ['content.create']],
            $authManager,
        );

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(statusCode: ResponseStatus::OK->value, body: 'OK');
            }
        };

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
    }

    /**
     * ASVS 7.2.2 — a denied access-control decision must reach the audit sink.
     *
     * Drives a real request through the kernel and asserts the decision on
     * disk in the JSON Lines file the framework actually ships, rather than
     * on a spy.
     */
    #[Test]
    public function deniedPermissionIsRecordedInTheAuditSink(): void
    {
        $auditPath = $this->createAuditLogPath();
        $auditLogger = new AuditLogger(new AuditFileSink($auditPath), random_bytes(32));

        $kernel = $this->createKernelGuardingRoute(
            new Identity(id: 'viewer-1', displayName: 'Viewer', roles: ['viewer']),
            $auditLogger,
            ['permissions' => ['content.create']],
        );

        $response = $kernel->handle(new ServerRequest(method: 'GET', uri: '/content/create'));

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());

        $entries = $this->readAuditEntries($auditPath);

        self::assertCount(1, $entries);
        self::assertSame('authorization', $entries[0]['event']);
        self::assertSame('denied', $entries[0]['outcome']);
        self::assertSame('viewer-1', $entries[0]['actor']);
        self::assertSame('authorize', $entries[0]['action']);
        self::assertSame('/content/create', $entries[0]['resource']);
        self::assertSame(['permission' => 'content.create'], $entries[0]['metadata']);
    }

    /**
     * A fail-closed denial the gate never saw. ASVS 7.2.2 asks for all failed
     * access-control decisions, not only the ones a policy refused.
     */
    #[Test]
    public function routeDeclaringNoPermissionsIsRecordedAsADeniedDecision(): void
    {
        $auditPath = $this->createAuditLogPath();
        $auditLogger = new AuditLogger(new AuditFileSink($auditPath), random_bytes(32));

        $kernel = $this->createKernelGuardingRoute(
            new Identity(id: 'editor-1', displayName: 'Editor', roles: ['editor']),
            $auditLogger,
            [],
        );

        $response = $kernel->handle(new ServerRequest(method: 'GET', uri: '/content/create'));

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());

        $entries = $this->readAuditEntries($auditPath);

        self::assertCount(1, $entries);
        self::assertSame('authorization', $entries[0]['event']);
        self::assertSame('denied', $entries[0]['outcome']);
        self::assertSame('editor-1', $entries[0]['actor']);
        self::assertSame(['permission' => 'no_permissions_declared'], $entries[0]['metadata']);
    }

    /**
     * The other fail-closed branch, reached when the middleware is registered
     * globally rather than on the route.
     */
    #[Test]
    public function requestWithoutRouteContextIsRecordedAsADeniedDecision(): void
    {
        $auditPath = $this->createAuditLogPath();
        $auditLogger = new AuditLogger(new AuditFileSink($auditPath), random_bytes(32));

        $identity = new Identity(id: 'editor-1', displayName: 'Editor', roles: ['editor']);
        $authManager = $this->createStub(AuthManagerInterface::class);
        $authManager->method('authenticate')->willReturn($identity);

        // Registered globally, the middleware runs before the router has
        // matched, so the required permissions are unknown.
        $kernel = new Kernel();
        $kernel->addMiddleware(new AuthenticationMiddleware($authManager));
        $kernel->addMiddleware(new AuthorizationMiddleware($this->gate, $auditLogger));
        $kernel->router()->add(new Route(
            methods: [Method::GET],
            path: '/content/create',
            handler: static fn(): Response => Response::text('created'),
            attributes: ['permissions' => ['content.create']],
        ));

        $response = $kernel->handle(new ServerRequest(method: 'GET', uri: '/content/create'));

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());

        $entries = $this->readAuditEntries($auditPath);

        self::assertCount(1, $entries);
        self::assertSame('authorization', $entries[0]['event']);
        self::assertSame('denied', $entries[0]['outcome']);
        self::assertSame('editor-1', $entries[0]['actor']);
        self::assertSame(['permission' => 'no_route_context'], $entries[0]['metadata']);
    }

    /**
     * @param array<string, mixed> $routeAttributes
     */
    private function createKernelGuardingRoute(
        Identity $identity,
        AuditLogger $auditLogger,
        array $routeAttributes,
    ): Kernel {
        $authManager = $this->createStub(AuthManagerInterface::class);
        $authManager->method('authenticate')->willReturn($identity);

        // Wired the way AuthWiring wires it: AuthenticationMiddleware global,
        // AuthorizationMiddleware behind the `auth` route alias.
        $kernel = new Kernel();
        $kernel->addMiddleware(new AuthenticationMiddleware($authManager));
        $kernel->middlewareRegistry()->alias('auth', new AuthorizationMiddleware($this->gate, $auditLogger));
        $kernel->router()->add(new Route(
            methods: [Method::GET],
            path: '/content/create',
            handler: static fn(): Response => Response::text('created'),
            attributes: $routeAttributes,
            middleware: ['auth'],
        ));

        return $kernel;
    }

    private function createAuditLogPath(): string
    {
        return $this->auditLogPath = sys_get_temp_dir()
            . '/pulsar_authz_flow_audit_' . bin2hex(random_bytes(8)) . '.jsonl';
    }

    /**
     * @return list<array{event: string, outcome: string, actor: string, action: string, resource: string, metadata: array<string, mixed>}>
     */
    private function readAuditEntries(string $path): array
    {
        self::assertFileExists($path, 'the decision reached no audit sink');

        $raw = file_get_contents($path);
        self::assertIsString($raw);

        $entries = [];

        foreach (explode("\n", trim($raw)) as $line) {
            /** @var array{event: string, outcome: string, actor: string, action: string, resource: string, metadata: array<string, mixed>} $entry */
            $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * @param array<string, mixed> $routeAttributes
     */
    private function createRequestWithRoute(
        string $path,
        array $routeAttributes,
        AuthManagerInterface $authManager,
    ): ServerRequestInterface {
        $route = new Route(
            methods: [Method::GET],
            path: $path,
            handler: fn() => new Response(),
            attributes: $routeAttributes,
        );

        $matchedRoute = new MatchedRoute($route);

        $request = new ServerRequest(
            method: 'GET',
            uri: $path,
            headers: [],
        );

        $securityContext = new SecurityContext($authManager, $request);

        return $request
            ->withAttribute('_route', $matchedRoute)
            ->withAttribute('_security_context', $securityContext);
    }
}
