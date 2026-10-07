<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Http\Controller\Admin;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Exception\AuthorizationException;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Forum\Domain\BanType;
use Pulsar\Extension\Forum\Http\Controller\Admin\BanController;
use Pulsar\Extension\Forum\Report\UserBan;
use Pulsar\Extension\Forum\Report\UserBanRepositoryInterface;
use Pulsar\Extension\Forum\Service\BanServiceInterface;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(BanController::class)]
final class BanControllerTest extends TestCase
{
    private function makeIdentity(): IdentityInterface
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('id')->willReturn('mod-1');
        $identity->method('isAuthenticated')->willReturn(true);

        return $identity;
    }

    private function makeAllowGate(): GateInterface
    {
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(false);

        return $gate;
    }

    private function makeBan(bool $active = true): UserBan
    {
        return UserBan::create(
            userId: 'user-1',
            bannedBy: 'mod-1',
            reason: 'Spam',
            type: BanType::Temporary,
            expiresAt: $active ? new DateTimeImmutable('+7 days') : new DateTimeImmutable('-1 day'),
        );
    }

    private function makeController(
        ?GateInterface $gate = null,
        ?BanServiceInterface $banService = null,
        ?UserBanRepositoryInterface $banRepo = null,
    ): BanController {
        if ($banRepo === null) {
            $banRepo = $this->createStub(UserBanRepositoryInterface::class);
            $banRepo->method('findActive')->willReturn(new PaginationResult([], 0, false, 20));
        }

        return new BanController(
            banService: $banService ?? $this->createStub(BanServiceInterface::class),
            banRepository: $banRepo,
            gate: $gate ?? $this->makeAllowGate(),
        );
    }

    #[Test]
    public function indexWithoutPermissionThrows(): void
    {
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $controller = $this->makeController(gate: $gate);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/admin/forum/bans',
            headers: ['Accept' => 'application/json'],
        )->withAttribute('identity', $this->makeIdentity());

        $this->expectException(AuthorizationException::class);

        $controller->index($request);
    }

    #[Test]
    public function indexReturnsActiveBans(): void
    {
        $ban = $this->makeBan();

        $banRepo = $this->createStub(UserBanRepositoryInterface::class);
        $banRepo->method('findActive')->willReturn(new PaginationResult(
            items: [$ban],
            total: 1,
            hasMore: false,
            perPage: 20,
        ));

        $controller = $this->makeController(banRepo: $banRepo);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/admin/forum/bans',
            headers: ['Accept' => 'application/json'],
        )->withAttribute('identity', $this->makeIdentity());

        $response = $controller->index($request);

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertCount(1, $body['data']);
        self::assertSame('user-1', $body['data'][0]['user_id']);
        self::assertTrue($body['data'][0]['is_active']);
        self::assertArrayHasKey('pagination', $body);
    }

    #[Test]
    public function showReturns404WhenBanNotFound(): void
    {
        $banRepo = $this->createStub(UserBanRepositoryInterface::class);
        $banRepo->method('findById')->willReturn(null);

        $controller = $this->makeController(banRepo: $banRepo);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/admin/forum/bans/nonexistent',
            headers: ['Accept' => 'application/json'],
        )->withAttribute('identity', $this->makeIdentity());

        $response = $controller->show($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function showReturnsBanWithHistory(): void
    {
        $ban = $this->makeBan();

        $banRepo = $this->createStub(UserBanRepositoryInterface::class);
        $banRepo->method('findById')->willReturn($ban);
        $banRepo->method('findByUser')->willReturn([$ban]);

        $controller = $this->makeController(banRepo: $banRepo);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/admin/forum/bans/' . $ban->id,
            headers: ['Accept' => 'application/json'],
        )->withAttribute('identity', $this->makeIdentity());

        $response = $controller->show($request, $ban->id);

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('ban', $body);
        self::assertArrayHasKey('user_ban_history', $body);
        self::assertSame('user-1', $body['ban']['user_id']);
    }

    #[Test]
    public function createWithMissingFieldsReturns422(): void
    {
        $controller = $this->makeController();

        $request = new ServerRequest(
            method: 'POST',
            uri: '/admin/forum/bans',
            headers: ['Accept' => 'application/json'],
        )->withAttribute('identity', $this->makeIdentity())
            ->withParsedBody(['user_id' => '', 'reason' => '']);

        $response = $controller->create($request);

        self::assertSame(422, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertNotNull($body['details']['user_id']);
        self::assertNotNull($body['details']['reason']);
    }

    #[Test]
    public function createBanReturns201OnSuccess(): void
    {
        $ban = $this->makeBan();

        $banService = $this->createStub(BanServiceInterface::class);
        $banService->method('ban')->willReturn($ban);

        $controller = $this->makeController(banService: $banService);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/admin/forum/bans',
            headers: ['Accept' => 'application/json'],
        )->withAttribute('identity', $this->makeIdentity())
            ->withParsedBody([
                'user_id' => 'user-1',
                'reason' => 'Spam',
                'type' => 'temporary',
                'expires_at' => '2026-12-31T00:00:00+00:00',
            ]);

        $response = $controller->create($request);

        self::assertSame(201, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('user-1', $body['data']['user_id']);
        self::assertSame('temporary', $body['data']['type']);
    }

    #[Test]
    public function createWithInvalidExpiresAtReturns422(): void
    {
        $controller = $this->makeController();

        $request = new ServerRequest(
            method: 'POST',
            uri: '/admin/forum/bans',
            headers: ['Accept' => 'application/json'],
        )->withAttribute('identity', $this->makeIdentity())
            ->withParsedBody([
                'user_id' => 'user-1',
                'reason' => 'Spam',
                'type' => 'temporary',
                'expires_at' => 'not-a-date',
            ]);

        $response = $controller->create($request);

        self::assertSame(422, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('expires_at', $body['details']);
    }

    #[Test]
    public function revokeReturns404WhenBanNotFound(): void
    {
        $banRepo = $this->createStub(UserBanRepositoryInterface::class);
        $banRepo->method('findById')->willReturn(null);

        $controller = $this->makeController(banRepo: $banRepo);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/admin/forum/bans/nonexistent/revoke',
            headers: ['Accept' => 'application/json'],
        )->withAttribute('identity', $this->makeIdentity());

        $response = $controller->revoke($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function revokeInactiveBanReturns422(): void
    {
        $ban = $this->makeBan(active: false);

        $banRepo = $this->createStub(UserBanRepositoryInterface::class);
        $banRepo->method('findById')->willReturn($ban);

        $controller = $this->makeController(banRepo: $banRepo);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/admin/forum/bans/' . $ban->id . '/revoke',
            headers: ['Accept' => 'application/json'],
        )->withAttribute('identity', $this->makeIdentity());

        $response = $controller->revoke($request, $ban->id);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function revokeActiveBanReturnsSuccess(): void
    {
        $ban = $this->makeBan(active: true);

        $banRepo = $this->createStub(UserBanRepositoryInterface::class);
        $banRepo->method('findById')->willReturn($ban);

        $banService = $this->createStub(BanServiceInterface::class);

        $controller = $this->makeController(banService: $banService, banRepo: $banRepo);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/admin/forum/bans/' . $ban->id . '/revoke',
            headers: ['Accept' => 'application/json'],
        )->withAttribute('identity', $this->makeIdentity());

        $response = $controller->revoke($request, $ban->id);

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('revoked', $body['data']['status']);
    }
}
