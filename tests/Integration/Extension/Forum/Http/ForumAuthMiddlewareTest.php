<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extension\Forum\Http;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Auth\Identity\TwoFactorStatus;
use Pulsar\Extension\Forum\Config\ForumConfig;
use Pulsar\Extension\Forum\Http\Middleware\ForumAuthMiddleware;
use Pulsar\Extension\Forum\Profile\ForumProfile;
use Pulsar\Extension\Forum\Profile\ForumProfileRepositoryInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(ForumAuthMiddleware::class)]
final class ForumAuthMiddlewareTest extends TestCase
{
    #[Test]
    public function guestCanViewWhenAllowed(): void
    {
        $config = new ForumConfig(allowGuestViewing: true);
        $middleware = new ForumAuthMiddleware($config);

        $request = new ServerRequest(method: 'GET', uri: '/api/v1/forum/threads');
        $handler = $this->createPassthroughHandler();

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function guestCannotViewWhenDisallowed(): void
    {
        $config = new ForumConfig(allowGuestViewing: false);
        $middleware = new ForumAuthMiddleware($config);

        $request = new ServerRequest(method: 'GET', uri: '/api/v1/forum/threads');
        $handler = $this->createPassthroughHandler();

        $response = $middleware->process($request, $handler);

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function unauthenticatedPostReturns401(): void
    {
        $config = new ForumConfig();
        $middleware = new ForumAuthMiddleware($config);

        $request = new ServerRequest(method: 'POST', uri: '/api/v1/forum/threads');
        $handler = $this->createPassthroughHandler();

        $response = $middleware->process($request, $handler);

        self::assertSame(401, $response->getStatusCode());
        $data = $this->decodeBody($response);
        self::assertSame('Authentication required', $data['error']);
    }

    #[Test]
    public function authenticatedUserCanPost(): void
    {
        $config = new ForumConfig();
        $middleware = new ForumAuthMiddleware($config);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/threads',
            attributes: ['identity' => $this->createIdentity('user-001')],
        );
        $handler = $this->createPassthroughHandler();

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function gateDeniesForbidsAccess(): void
    {
        $config = new ForumConfig();
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $middleware = new ForumAuthMiddleware($config, $gate);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/threads',
            attributes: ['identity' => $this->createIdentity('user-001')],
        );
        $handler = $this->createPassthroughHandler();

        $response = $middleware->process($request, $handler);

        self::assertSame(403, $response->getStatusCode());
        $data = $this->decodeBody($response);
        self::assertSame('Forum access denied', $data['error']);
    }

    #[Test]
    public function gateAllowsAccess(): void
    {
        $config = new ForumConfig();
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(false);

        $middleware = new ForumAuthMiddleware($config, $gate);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/threads',
            attributes: ['identity' => $this->createIdentity('user-001')],
        );
        $handler = $this->createPassthroughHandler();

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function bannedUserIsForbidden(): void
    {
        $config = new ForumConfig();
        $profiles = $this->createStub(ForumProfileRepositoryInterface::class);
        $bannedProfile = new ForumProfile(
            id: 'prof-banned',
            tenantId: null,
            userId: 'user-banned',
            reputationScore: 0,
            postCount: 0,
            threadCount: 0,
            isBanned: true,
            banReason: 'Spam',
            bannedAt: new DateTimeImmutable(),
            banExpiresAt: null,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );
        $profiles->method('findByUser')->willReturn($bannedProfile);

        $middleware = new ForumAuthMiddleware($config, null, $profiles);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/threads',
            attributes: ['identity' => $this->createIdentity('user-banned')],
        );
        $handler = $this->createPassthroughHandler();

        $response = $middleware->process($request, $handler);

        self::assertSame(403, $response->getStatusCode());
        $data = $this->decodeBody($response);
        self::assertStringContainsString('banned', $data['error']);
    }

    #[Test]
    public function expiredBanAllowsAccess(): void
    {
        $config = new ForumConfig();
        $profiles = $this->createStub(ForumProfileRepositoryInterface::class);
        $expiredBan = new ForumProfile(
            id: 'prof-expired',
            tenantId: null,
            userId: 'user-expired',
            reputationScore: 0,
            postCount: 0,
            threadCount: 0,
            isBanned: true,
            banReason: 'Temporary',
            bannedAt: new DateTimeImmutable('-2 days'),
            banExpiresAt: new DateTimeImmutable('-1 day'),
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );
        $profiles->method('findByUser')->willReturn($expiredBan);

        $middleware = new ForumAuthMiddleware($config, null, $profiles);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/threads',
            attributes: ['identity' => $this->createIdentity('user-expired')],
        );
        $handler = $this->createPassthroughHandler();

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function headRequestTreatedAsReadOnly(): void
    {
        $config = new ForumConfig(allowGuestViewing: true);
        $middleware = new ForumAuthMiddleware($config);

        $request = new ServerRequest(method: 'HEAD', uri: '/api/v1/forum/threads');
        $handler = $this->createPassthroughHandler();

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function optionsRequestTreatedAsReadOnly(): void
    {
        $config = new ForumConfig(allowGuestViewing: true);
        $middleware = new ForumAuthMiddleware($config);

        $request = new ServerRequest(method: 'OPTIONS', uri: '/api/v1/forum/threads');
        $handler = $this->createPassthroughHandler();

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function nonBannedUserCanPost(): void
    {
        $config = new ForumConfig();
        $profiles = $this->createStub(ForumProfileRepositoryInterface::class);
        $normalProfile = new ForumProfile(
            id: 'prof-ok',
            tenantId: null,
            userId: 'user-ok',
            reputationScore: 100,
            postCount: 50,
            threadCount: 10,
            isBanned: false,
            banReason: null,
            bannedAt: null,
            banExpiresAt: null,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );
        $profiles->method('findByUser')->willReturn($normalProfile);

        $middleware = new ForumAuthMiddleware($config, null, $profiles);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/threads',
            attributes: ['identity' => $this->createIdentity('user-ok')],
        );
        $handler = $this->createPassthroughHandler();

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    private function createIdentity(string $id): IdentityInterface
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('id')->willReturn($id);
        $identity->method('isAuthenticated')->willReturn(true);
        $identity->method('displayName')->willReturn('Test User');
        $identity->method('roles')->willReturn([]);
        $identity->method('hasRole')->willReturn(false);
        $identity->method('twoFactorStatus')->willReturn(TwoFactorStatus::Disabled);
        $identity->method('attributes')->willReturn([]);

        return $identity;
    }

    private function createPassthroughHandler(): RequestHandlerInterface
    {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(200));

        return $handler;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeBody(ResponseInterface $response): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
