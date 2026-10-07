<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Http\Controller\Admin;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Exception\AuthorizationException;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Forum\Http\Controller\Admin\LeaderboardController;
use Pulsar\Extension\Forum\Profile\ForumProfile;
use Pulsar\Extension\Forum\Service\LeaderboardServiceInterface;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(LeaderboardController::class)]
final class LeaderboardControllerTest extends TestCase
{
    private function makeIdentity(): IdentityInterface
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('id')->willReturn('admin-1');
        $identity->method('isAuthenticated')->willReturn(true);

        return $identity;
    }

    private function makeAllowGate(): GateInterface
    {
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(false);

        return $gate;
    }

    private function makeProfile(): ForumProfile
    {
        $now = new DateTimeImmutable('2025-01-15 12:00:00');

        return new ForumProfile(
            id: 'profile-1',
            tenantId: null,
            userId: 'user-1',
            reputationScore: 500,
            postCount: 100,
            threadCount: 20,
            isBanned: false,
            banReason: null,
            bannedAt: null,
            banExpiresAt: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    #[Test]
    public function indexWithoutPermissionThrows(): void
    {
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $controller = new LeaderboardController(
            $this->createStub(LeaderboardServiceInterface::class),
            $gate,
        );

        $request = new ServerRequest(
            method: 'GET',
            uri: '/admin/forum/leaderboard',
            headers: ['Accept' => 'application/json'],
        )->withAttribute('identity', $this->makeIdentity());

        $this->expectException(AuthorizationException::class);

        $controller->index($request);
    }

    #[Test]
    public function indexReturnsLeaderboardData(): void
    {
        $profile = $this->makeProfile();

        $leaderboardService = $this->createStub(LeaderboardServiceInterface::class);
        $leaderboardService->method('getTopUsers')->willReturn([
            ['rank' => 1, 'profile' => $profile],
        ]);

        $controller = new LeaderboardController($leaderboardService, $this->makeAllowGate());

        $request = new ServerRequest(
            method: 'GET',
            uri: '/admin/forum/leaderboard',
            headers: ['Accept' => 'application/json'],
        )->withAttribute('identity', $this->makeIdentity());

        $response = $controller->index($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertCount(1, $body['data']);
        self::assertSame(1, $body['data'][0]['rank']);
        self::assertSame('user-1', $body['data'][0]['user_id']);
        self::assertSame(500, $body['data'][0]['reputation_score']);
        self::assertSame('all', $body['period']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function periodProvider(): iterable
    {
        yield 'all time' => ['all', 'all'];
        yield 'month' => ['month', 'month'];
        yield 'week' => ['week', 'week'];
        yield 'invalid falls back to all' => ['invalid', 'all'];
    }

    #[Test]
    #[DataProvider('periodProvider')]
    public function indexRespectsPeriodParam(string $input, string $expected): void
    {
        $leaderboardService = $this->createStub(LeaderboardServiceInterface::class);
        $leaderboardService->method('getTopUsers')->willReturn([]);

        $controller = new LeaderboardController($leaderboardService, $this->makeAllowGate());

        $request = new ServerRequest(
            method: 'GET',
            uri: '/admin/forum/leaderboard',
            headers: ['Accept' => 'application/json'],
            queryParams: ['period' => $input],
        )->withAttribute('identity', $this->makeIdentity());

        $response = $controller->index($request);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame($expected, $body['period']);
    }
}
