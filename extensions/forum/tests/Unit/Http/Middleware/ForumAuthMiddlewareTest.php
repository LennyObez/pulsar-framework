<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Http\Middleware;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Forum\Config\ForumConfig;
use Pulsar\Extension\Forum\Http\Middleware\ForumAuthMiddleware;
use Pulsar\Extension\Forum\Profile\ForumProfile;
use Pulsar\Extension\Forum\Profile\ForumProfileRepositoryInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(ForumAuthMiddleware::class)]
final class ForumAuthMiddlewareTest extends TestCase
{
    private function makeHandler(int $statusCode = 200): RequestHandlerInterface
    {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response($statusCode));

        return $handler;
    }

    private function makeIdentity(string $id = 'user-1', bool $authenticated = true): IdentityInterface
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('id')->willReturn($id);
        $identity->method('isAuthenticated')->willReturn($authenticated);

        return $identity;
    }

    #[Test]
    #[DataProvider('readOnlyMethodProvider')]
    public function readOnlyRequestsPassThroughWhenGuestViewingEnabled(string $method): void
    {
        $config = new ForumConfig(allowGuestViewing: true);
        $middleware = new ForumAuthMiddleware($config);

        $request = new ServerRequest(method: $method, uri: '/forum');
        $handler = $this->makeHandler();

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function readOnlyMethodProvider(): iterable
    {
        yield 'GET' => ['GET'];
        yield 'HEAD' => ['HEAD'];
        yield 'OPTIONS' => ['OPTIONS'];
    }

    #[Test]
    public function readOnlyRequestsBlockedWhenGuestViewingDisabledAndNoIdentity(): void
    {
        $config = new ForumConfig(allowGuestViewing: false);
        $middleware = new ForumAuthMiddleware($config);

        $request = new ServerRequest(method: 'GET', uri: '/forum');
        $handler = $this->makeHandler();

        $response = $middleware->process($request, $handler);

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function writeRequestWithoutIdentityReturns401(): void
    {
        $config = new ForumConfig();
        $middleware = new ForumAuthMiddleware($config);

        $request = new ServerRequest(method: 'POST', uri: '/forum/threads');
        $handler = $this->makeHandler();

        $response = $middleware->process($request, $handler);

        self::assertSame(401, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Authentication required', $body['error']);
    }

    #[Test]
    public function writeRequestWithUnauthenticatedIdentityReturns401(): void
    {
        $config = new ForumConfig();
        $middleware = new ForumAuthMiddleware($config);

        $identity = $this->makeIdentity(authenticated: false);
        $request = new ServerRequest(method: 'POST', uri: '/forum/threads')
            ->withAttribute('identity', $identity);

        $handler = $this->makeHandler();
        $response = $middleware->process($request, $handler);

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function authenticatedWriteRequestPassesThrough(): void
    {
        $config = new ForumConfig();
        $middleware = new ForumAuthMiddleware($config);

        $identity = $this->makeIdentity();
        $request = new ServerRequest(method: 'POST', uri: '/forum/threads')
            ->withAttribute('identity', $identity);

        $handler = $this->makeHandler();
        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function gateDenialReturns403(): void
    {
        $config = new ForumConfig();

        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $middleware = new ForumAuthMiddleware($config, $gate);

        $identity = $this->makeIdentity();
        $request = new ServerRequest(method: 'POST', uri: '/forum/threads')
            ->withAttribute('identity', $identity);

        $handler = $this->makeHandler();
        $response = $middleware->process($request, $handler);

        self::assertSame(403, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Forum access denied', $body['error']);
    }

    #[Test]
    public function gateAllowPassesThrough(): void
    {
        $config = new ForumConfig();

        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(false);

        $middleware = new ForumAuthMiddleware($config, $gate);

        $identity = $this->makeIdentity();
        $request = new ServerRequest(method: 'POST', uri: '/forum/threads')
            ->withAttribute('identity', $identity);

        $handler = $this->makeHandler();
        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function bannedUserWithActiveBanReturns403(): void
    {
        $config = new ForumConfig();

        $profile = new ForumProfile(
            id: 'prof-1',
            tenantId: null,
            userId: 'user-1',
            reputationScore: 0,
            postCount: 0,
            threadCount: 0,
            isBanned: true,
            banReason: 'Spam',
            bannedAt: new DateTimeImmutable('-1 day'),
            banExpiresAt: null, // permanent
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );

        $profiles = $this->createStub(ForumProfileRepositoryInterface::class);
        $profiles->method('findByUser')->willReturn($profile);

        $middleware = new ForumAuthMiddleware($config, null, $profiles);

        $identity = $this->makeIdentity();
        $request = new ServerRequest(method: 'POST', uri: '/forum/threads')
            ->withAttribute('identity', $identity);

        $handler = $this->makeHandler();
        $response = $middleware->process($request, $handler);

        self::assertSame(403, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertStringContainsString('banned', $body['error']);
        self::assertSame('Spam', $body['reason']);
    }

    #[Test]
    public function bannedUserWithExpiredBanPassesThrough(): void
    {
        $config = new ForumConfig();

        $profile = new ForumProfile(
            id: 'prof-1',
            tenantId: null,
            userId: 'user-1',
            reputationScore: 0,
            postCount: 0,
            threadCount: 0,
            isBanned: true,
            banReason: 'Spam',
            bannedAt: new DateTimeImmutable('-7 days'),
            banExpiresAt: new DateTimeImmutable('-1 day'), // already expired
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );

        $profiles = $this->createStub(ForumProfileRepositoryInterface::class);
        $profiles->method('findByUser')->willReturn($profile);

        $middleware = new ForumAuthMiddleware($config, null, $profiles);

        $identity = $this->makeIdentity();
        $request = new ServerRequest(method: 'POST', uri: '/forum/threads')
            ->withAttribute('identity', $identity);

        $handler = $this->makeHandler();
        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function unbannedUserPassesThrough(): void
    {
        $config = new ForumConfig();

        $profile = new ForumProfile(
            id: 'prof-1',
            tenantId: null,
            userId: 'user-1',
            reputationScore: 50,
            postCount: 10,
            threadCount: 3,
            isBanned: false,
            banReason: null,
            bannedAt: null,
            banExpiresAt: null,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );

        $profiles = $this->createStub(ForumProfileRepositoryInterface::class);
        $profiles->method('findByUser')->willReturn($profile);

        $middleware = new ForumAuthMiddleware($config, null, $profiles);

        $identity = $this->makeIdentity();
        $request = new ServerRequest(method: 'POST', uri: '/forum/threads')
            ->withAttribute('identity', $identity);

        $handler = $this->makeHandler();
        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }
}
