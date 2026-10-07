<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Http\Controller\Api;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Http\Controller\Api\LeaderboardController;
use Pulsar\Extension\Forum\Profile\ForumProfile;
use Pulsar\Extension\Forum\Service\LeaderboardServiceInterface;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(LeaderboardController::class)]
final class ApiLeaderboardControllerTest extends TestCase
{
    private function makeProfile(
        string $userId = 'user-1',
        int $reputationScore = 500,
    ): ForumProfile {
        $now = new DateTimeImmutable('2025-01-15 12:00:00');

        return new ForumProfile(
            id: 'profile-1',
            tenantId: null,
            userId: $userId,
            reputationScore: $reputationScore,
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

    private function makeRequest(array $queryParams = []): ServerRequest
    {
        return new ServerRequest(
            method: 'GET',
            uri: '/api/v1/forum/leaderboard',
            headers: ['Accept' => 'application/json'],
            queryParams: $queryParams,
        );
    }

    #[Test]
    public function indexReturnsLeaderboardData(): void
    {
        $profile = $this->makeProfile();

        $service = $this->createStub(LeaderboardServiceInterface::class);
        $service->method('getTopUsers')->willReturn([
            ['rank' => 1, 'profile' => $profile],
        ]);

        $controller = new LeaderboardController($service);

        $response = $controller->index($this->makeRequest());

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertCount(1, $body['data']);
        self::assertSame(1, $body['data'][0]['rank']);
        self::assertSame('user-1', $body['data'][0]['user_id']);
        self::assertSame(500, $body['data'][0]['reputation_score']);
        self::assertSame('all', $body['period']);
        self::assertSame(25, $body['limit']);
    }

    #[Test]
    public function indexReturnsEmptyLeaderboard(): void
    {
        $service = $this->createStub(LeaderboardServiceInterface::class);
        $service->method('getTopUsers')->willReturn([]);

        $controller = new LeaderboardController($service);

        $response = $controller->index($this->makeRequest());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertCount(0, $body['data']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function periodProvider(): iterable
    {
        yield 'all' => ['all', 'all'];
        yield 'month' => ['month', 'month'];
        yield 'week' => ['week', 'week'];
        yield 'invalid falls back' => ['invalid', 'all'];
    }

    #[Test]
    #[DataProvider('periodProvider')]
    public function indexRespectsPeriodParam(string $input, string $expected): void
    {
        $service = $this->createStub(LeaderboardServiceInterface::class);
        $service->method('getTopUsers')->willReturn([]);

        $controller = new LeaderboardController($service);

        $response = $controller->index($this->makeRequest(['period' => $input]));

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame($expected, $body['period']);
    }

    #[Test]
    public function indexRespectsLimitParam(): void
    {
        $service = $this->createStub(LeaderboardServiceInterface::class);
        $service->method('getTopUsers')->willReturn([]);

        $controller = new LeaderboardController($service);

        $response = $controller->index($this->makeRequest(['limit' => '10']));

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(10, $body['limit']);
    }
}
