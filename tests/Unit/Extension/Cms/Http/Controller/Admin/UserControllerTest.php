<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http\Controller\Admin;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Exception\AuthenticationException;
use Pulsar\Auth\Exception\AuthorizationException;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Auth\Identity\TwoFactorStatus;
use Pulsar\Extension\Cms\Http\Controller\Admin\UserController;
use Pulsar\Extension\Cms\Users\CmsUser;
use Pulsar\Extension\Cms\Users\CmsUserRepositoryInterface;

use function json_decode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(UserController::class)]
final class UserControllerTest extends TestCase
{
    #[Test]
    public function index_returns_user_list(): void
    {
        $user = $this->createUser('user-1');

        $repo = $this->createStub(CmsUserRepositoryInterface::class);
        $repo->method('listUsers')->willReturn(new PaginationResult(
            items: [$user],
            total: 1,
            hasMore: false,
            perPage: 20,
        ));

        $audit = $this->createStub(AuditLoggerInterface::class);
        $controller = new UserController(userRepository: $repo, auditLogger: $audit);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->index($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        /** @var list<array<string, mixed>> $users */
        $users = $body['users'];
        self::assertCount(1, $users);
        self::assertSame('user-1', $users[0]['id']);
        self::assertSame('Test User', $users[0]['display_name']);
        self::assertSame('verified', $users[0]['two_factor_status']);
        self::assertFalse($users[0]['is_locked']);
    }

    #[Test]
    public function show_returns_user_detail(): void
    {
        $user = $this->createUser('user-1');

        $repo = $this->createStub(CmsUserRepositoryInterface::class);
        $repo->method('findById')->willReturn($user);

        $audit = $this->createStub(AuditLoggerInterface::class);
        $controller = new UserController(userRepository: $repo, auditLogger: $audit);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->show($request, 'user-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> $userData */
        $userData = $body['user'];
        self::assertSame('user-1', $userData['id']);
        self::assertSame('test@example.com', $userData['email']);
    }

    #[Test]
    public function show_returns_404_when_user_not_found(): void
    {
        $repo = $this->createStub(CmsUserRepositoryInterface::class);
        $repo->method('findById')->willReturn(null);

        $audit = $this->createStub(AuditLoggerInterface::class);
        $controller = new UserController(userRepository: $repo, auditLogger: $audit);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->show($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function update_roles_returns_success(): void
    {
        $user = $this->createUser('user-1');

        $repo = $this->createStub(CmsUserRepositoryInterface::class);
        $repo->method('findById')->willReturn($user);

        $audit = $this->createStub(AuditLoggerInterface::class);
        $controller = new UserController(userRepository: $repo, auditLogger: $audit);
        $request = $this->createAuthenticatedRequest(
            stepUp: true,
            parsedBody: ['roles' => ['cms.editor', 'cms.admin']],
        );

        $response = $controller->update($request, 'user-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('updated', $body['status']);
    }

    #[Test]
    public function update_returns_400_for_invalid_role(): void
    {
        $user = $this->createUser('user-1');

        $repo = $this->createStub(CmsUserRepositoryInterface::class);
        $repo->method('findById')->willReturn($user);

        $audit = $this->createStub(AuditLoggerInterface::class);
        $controller = new UserController(userRepository: $repo, auditLogger: $audit);
        $request = $this->createAuthenticatedRequest(
            stepUp: true,
            parsedBody: ['roles' => ['admin.superuser']],
        );

        $response = $controller->update($request, 'user-1');

        self::assertSame(400, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsString($body['error']);
        self::assertStringContainsString('Invalid CMS role', $body['error']);
    }

    #[Test]
    public function update_returns_400_when_roles_not_array(): void
    {
        $user = $this->createUser('user-1');

        $repo = $this->createStub(CmsUserRepositoryInterface::class);
        $repo->method('findById')->willReturn($user);

        $audit = $this->createStub(AuditLoggerInterface::class);
        $controller = new UserController(userRepository: $repo, auditLogger: $audit);
        $request = $this->createAuthenticatedRequest(
            stepUp: true,
            parsedBody: ['roles' => 'not-an-array'],
        );

        $response = $controller->update($request, 'user-1');

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function update_returns_404_when_user_not_found(): void
    {
        $repo = $this->createStub(CmsUserRepositoryInterface::class);
        $repo->method('findById')->willReturn(null);

        $audit = $this->createStub(AuditLoggerInterface::class);
        $controller = new UserController(userRepository: $repo, auditLogger: $audit);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->update($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function reset_two_factor_returns_success(): void
    {
        $user = $this->createUser('user-1');

        $repo = $this->createStub(CmsUserRepositoryInterface::class);
        $repo->method('findById')->willReturn($user);

        $audit = $this->createStub(AuditLoggerInterface::class);
        $controller = new UserController(userRepository: $repo, auditLogger: $audit);
        $request = $this->createAuthenticatedRequest(
            stepUp: true,
            parsedBody: ['reason' => 'User lost their authenticator device'],
        );

        $response = $controller->resetTwoFactor($request, 'user-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('two_factor_reset', $body['status']);
    }

    #[Test]
    public function reset_two_factor_returns_400_when_reason_too_short(): void
    {
        $user = $this->createUser('user-1');

        $repo = $this->createStub(CmsUserRepositoryInterface::class);
        $repo->method('findById')->willReturn($user);

        $audit = $this->createStub(AuditLoggerInterface::class);
        $controller = new UserController(userRepository: $repo, auditLogger: $audit);
        $request = $this->createAuthenticatedRequest(
            stepUp: true,
            parsedBody: ['reason' => 'short'],
        );

        $response = $controller->resetTwoFactor($request, 'user-1');

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function reset_two_factor_returns_404_when_user_not_found(): void
    {
        $repo = $this->createStub(CmsUserRepositoryInterface::class);
        $repo->method('findById')->willReturn(null);

        $audit = $this->createStub(AuditLoggerInterface::class);
        $controller = new UserController(userRepository: $repo, auditLogger: $audit);
        $request = $this->createAuthenticatedRequest(
            stepUp: true,
            parsedBody: ['reason' => 'User requested 2FA reset via support'],
        );

        $response = $controller->resetTwoFactor($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function index_throws_when_unauthenticated(): void
    {
        $repo = $this->createStub(CmsUserRepositoryInterface::class);
        $audit = $this->createStub(AuditLoggerInterface::class);
        $controller = new UserController(userRepository: $repo, auditLogger: $audit);

        $this->expectException(AuthenticationException::class);
        $controller->index($this->createUnauthenticatedRequest());
    }

    #[Test]
    public function index_throws_when_authorization_denied(): void
    {
        $repo = $this->createStub(CmsUserRepositoryInterface::class);
        $audit = $this->createStub(AuditLoggerInterface::class);
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $controller = new UserController(
            userRepository: $repo,
            auditLogger: $audit,
            gate: $gate,
        );
        $request = $this->createAuthenticatedRequest();

        $this->expectException(AuthorizationException::class);
        $controller->index($request);
    }

    private function createUser(string $id): CmsUser
    {
        $now = new DateTimeImmutable('2026-03-10T12:00:00+00:00');

        return new CmsUser(
            id: $id,
            tenantId: null,
            displayName: 'Test User',
            email: 'test@example.com',
            roles: ['cms.editor'],
            twoFactorStatus: TwoFactorStatus::Verified,
            contentCount: 5,
            commentCount: 12,
            lastActiveAt: $now,
            createdAt: $now,
            isLocked: false,
        );
    }

    /**
     * @param array<string, mixed>|null $parsedBody
     */
    private function createAuthenticatedRequest(
        bool $stepUp = false,
        ?array $parsedBody = null,
    ): ServerRequestInterface {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);
        $identity->method('id')->willReturn('admin-1');

        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/users');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);
        $request->method('getQueryParams')->willReturn([]);
        $request->method('getAttribute')->willReturnCallback(
            static fn(string $name, mixed $default = null): mixed => match ($name) {
                'identity' => $identity,
                'step_up_verified' => $stepUp,
                default => $default,
            },
        );

        if ($parsedBody !== null) {
            $request->method('getParsedBody')->willReturn($parsedBody);
        }

        return $request;
    }

    private function createUnauthenticatedRequest(): ServerRequestInterface
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/users');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);
        $request->method('getAttribute')->willReturn(null);

        return $request;
    }
}
