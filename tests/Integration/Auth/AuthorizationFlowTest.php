<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\AuthManagerInterface;
use Pulsar\Auth\Authorization\Gate;
use Pulsar\Auth\Authorization\InMemoryRoleRegistry;
use Pulsar\Auth\Authorization\Permission;
use Pulsar\Auth\Authorization\PolicyContext;
use Pulsar\Auth\Authorization\PolicyInterface;
use Pulsar\Auth\Authorization\Role;
use Pulsar\Auth\Identity\AnonymousIdentity;
use Pulsar\Auth\Identity\Identity;
use Pulsar\Auth\Middleware\AuthorizationMiddleware;
use Pulsar\Auth\SecurityContext;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Routing\Route;

#[CoversClass(Gate::class)]
#[CoversClass(InMemoryRoleRegistry::class)]
#[CoversClass(AuthorizationMiddleware::class)]
final class AuthorizationFlowTest extends TestCase
{
    private InMemoryRoleRegistry $registry;
    private Gate $gate;

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

        $handler = fn(Request $req): Response => new Response(body: 'OK', status: ResponseStatus::OK);

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::Unauthorized, $response->status);
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

        $handler = fn(Request $req): Response => new Response(body: 'OK', status: ResponseStatus::OK);

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::OK, $response->status);
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

        $handler = fn(Request $req): Response => new Response(body: 'OK', status: ResponseStatus::OK);

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::Forbidden, $response->status);
    }

    /**
     * @param array<string, mixed> $routeAttributes
     */
    private function createRequestWithRoute(
        string $path,
        array $routeAttributes,
        AuthManagerInterface $authManager,
    ): Request {
        $route = new Route(
            methods: [Method::GET],
            path: $path,
            handler: fn() => new Response(),
            attributes: $routeAttributes,
        );

        $matchedRoute = new MatchedRoute($route);

        $request = new Request(
            method: Method::GET,
            uri: $path,
            path: $path,
            queryString: '',
            headers: new HeaderBag([]),
            body: '',
        );

        $securityContext = new SecurityContext($authManager, $request);

        return $request
            ->withAttribute('_route', $matchedRoute)
            ->withAttribute('_security_context', $securityContext);
    }
}
