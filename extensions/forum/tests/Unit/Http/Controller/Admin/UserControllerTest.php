<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Http\Controller\Admin;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Exception\AuthorizationException;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Forum\Badge\BadgeServiceInterface;
use Pulsar\Extension\Forum\Badge\UserBadge;
use Pulsar\Extension\Forum\Domain\Badge;
use Pulsar\Extension\Forum\Domain\ReputationLevel;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Http\Controller\Admin\UserController;
use Pulsar\Extension\Forum\Profile\ForumProfile;
use Pulsar\Extension\Forum\Profile\ForumProfileRepositoryInterface;
use Pulsar\Extension\Forum\Service\ModerationServiceInterface;
use Pulsar\Extension\Forum\Service\ReputationServiceInterface;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(UserController::class)]
final class UserControllerTest extends TestCase
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

    private function makeProfile(
        string $id = 'profile-1',
        string $userId = 'user-1',
        int $reputationScore = 500,
        bool $isBanned = false,
    ): ForumProfile {
        $now = new DateTimeImmutable('2025-01-15 12:00:00');

        return new ForumProfile(
            id: $id,
            tenantId: null,
            userId: $userId,
            reputationScore: $reputationScore,
            postCount: 100,
            threadCount: 20,
            isBanned: $isBanned,
            banReason: $isBanned ? 'Spam' : null,
            bannedAt: $isBanned ? $now : null,
            banExpiresAt: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    private function makeController(
        ?GateInterface $gate = null,
        ?ForumProfileRepositoryInterface $profileRepo = null,
        ?ModerationServiceInterface $modService = null,
        ?ReputationServiceInterface $repService = null,
        ?BadgeServiceInterface $badgeService = null,
    ): UserController {
        if ($badgeService === null) {
            $badgeService = $this->createStub(BadgeServiceInterface::class);
            $badgeService->method('getUserBadges')->willReturn([]);
        }

        if ($repService === null) {
            $repService = $this->createStub(ReputationServiceInterface::class);
            $repService->method('getLevel')->willReturn(ReputationLevel::Expert);
        }

        return new UserController(
            profileRepository: $profileRepo ?? $this->createStub(ForumProfileRepositoryInterface::class),
            moderationService: $modService ?? $this->createStub(ModerationServiceInterface::class),
            reputationService: $repService,
            badgeService: $badgeService,
            gate: $gate ?? $this->makeAllowGate(),
        );
    }

    private function makeRequest(
        string $method = 'GET',
        string $uri = '/admin/forum/users/user-1',
        ?array $parsedBody = null,
    ): ServerRequest {
        $request = new ServerRequest(
            method: $method,
            uri: $uri,
            headers: ['Accept' => 'application/json'],
        )->withAttribute('identity', $this->makeIdentity());

        if ($parsedBody !== null) {
            $request = $request->withParsedBody($parsedBody);
        }

        return $request;
    }

    #[Test]
    public function showWithoutPermissionThrows(): void
    {
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $controller = $this->makeController(gate: $gate);

        $this->expectException(AuthorizationException::class);

        $controller->show($this->makeRequest(), 'user-1');
    }

    #[Test]
    public function showReturns404WhenProfileNotFound(): void
    {
        $profileRepo = $this->createStub(ForumProfileRepositoryInterface::class);
        $profileRepo->method('findByUser')->willReturn(null);

        $controller = $this->makeController(profileRepo: $profileRepo);

        $response = $controller->show($this->makeRequest(), 'user-1');

        self::assertSame(404, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Forum profile not found', $body['error']);
    }

    #[Test]
    public function showReturnsProfileWithBadgesAndLevel(): void
    {
        $profile = $this->makeProfile();
        $badge = new UserBadge(
            id: 'ub-1',
            tenantId: null,
            userId: 'user-1',
            badge: Badge::Solver,
            awardedAt: new DateTimeImmutable('2025-03-01'),
        );

        $profileRepo = $this->createStub(ForumProfileRepositoryInterface::class);
        $profileRepo->method('findByUser')->willReturn($profile);

        $badgeService = $this->createStub(BadgeServiceInterface::class);
        $badgeService->method('getUserBadges')->willReturn([$badge]);

        $repService = $this->createStub(ReputationServiceInterface::class);
        $repService->method('getLevel')->willReturn(ReputationLevel::Expert);

        $controller = $this->makeController(
            profileRepo: $profileRepo,
            badgeService: $badgeService,
            repService: $repService,
        );

        $response = $controller->show($this->makeRequest(), 'user-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame('profile-1', $body['profile']['id']);
        self::assertSame('user-1', $body['profile']['user_id']);
        self::assertSame(500, $body['profile']['reputation_score']);
        self::assertSame(100, $body['profile']['post_count']);
        self::assertSame(20, $body['profile']['thread_count']);
        self::assertFalse($body['profile']['is_banned']);
        self::assertSame(500, $body['reputation_level']);

        self::assertCount(1, $body['badges']);
        self::assertSame('solver', $body['badges'][0]['badge']);
    }

    #[Test]
    public function banWithoutReasonReturns422(): void
    {
        $controller = $this->makeController();

        $response = $controller->ban(
            $this->makeRequest('POST', '/admin/forum/users/user-1/ban', ['reason' => '']),
            'user-1',
        );

        self::assertSame(422, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Ban reason is required', $body['error']);
    }

    #[Test]
    public function banWithMissingReasonReturns422(): void
    {
        $controller = $this->makeController();

        $response = $controller->ban(
            $this->makeRequest('POST', '/admin/forum/users/user-1/ban', []),
            'user-1',
        );

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function banReturnsProfileOnSuccess(): void
    {
        $bannedProfile = $this->makeProfile(isBanned: true);

        $modService = $this->createStub(ModerationServiceInterface::class);
        $modService->method('banUser')->willReturn($bannedProfile);

        $controller = $this->makeController(modService: $modService);

        $response = $controller->ban(
            $this->makeRequest('POST', '/admin/forum/users/user-1/ban', [
                'reason' => 'Repeated spam',
            ]),
            'user-1',
        );

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertTrue($body['data']['is_banned']);
        self::assertSame('Spam', $body['data']['ban_reason']);
    }

    #[Test]
    public function banWithExpirationPassesDateToService(): void
    {
        $bannedProfile = $this->makeProfile(isBanned: true);

        $modService = $this->createStub(ModerationServiceInterface::class);
        $modService->method('banUser')->willReturn($bannedProfile);

        $controller = $this->makeController(modService: $modService);

        $response = $controller->ban(
            $this->makeRequest('POST', '/admin/forum/users/user-1/ban', [
                'reason' => 'Timeout',
                'expires_at' => '2026-12-31T23:59:59+00:00',
            ]),
            'user-1',
        );

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function banServiceExceptionReturns422(): void
    {
        $modService = $this->createStub(ModerationServiceInterface::class);
        $modService->method('banUser')->willThrowException(
            new ForumException('User is already banned'),
        );

        $controller = $this->makeController(modService: $modService);

        $response = $controller->ban(
            $this->makeRequest('POST', '/admin/forum/users/user-1/ban', [
                'reason' => 'Spam',
            ]),
            'user-1',
        );

        self::assertSame(422, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('User is already banned', $body['error']);
    }

    #[Test]
    public function unbanReturnsProfileOnSuccess(): void
    {
        $profile = $this->makeProfile(isBanned: false);

        $modService = $this->createStub(ModerationServiceInterface::class);
        $modService->method('unbanUser')->willReturn($profile);

        $controller = $this->makeController(modService: $modService);

        $response = $controller->unban($this->makeRequest('POST', '/admin/forum/users/user-1/unban'), 'user-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertFalse($body['data']['is_banned']);
    }

    #[Test]
    public function unbanServiceExceptionReturns422(): void
    {
        $modService = $this->createStub(ModerationServiceInterface::class);
        $modService->method('unbanUser')->willThrowException(
            new ForumException('User is not banned'),
        );

        $controller = $this->makeController(modService: $modService);

        $response = $controller->unban($this->makeRequest('POST', '/admin/forum/users/user-1/unban'), 'user-1');

        self::assertSame(422, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('User is not banned', $body['error']);
    }

    #[Test]
    public function promoteWithZeroPointsReturns422(): void
    {
        $controller = $this->makeController();

        $response = $controller->promote(
            $this->makeRequest('POST', '/admin/forum/users/user-1/promote', ['points' => 0]),
            'user-1',
        );

        self::assertSame(422, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Points must be non-zero', $body['error']);
    }

    #[Test]
    public function promoteWithMissingPointsReturns422(): void
    {
        $controller = $this->makeController();

        $response = $controller->promote(
            $this->makeRequest('POST', '/admin/forum/users/user-1/promote', []),
            'user-1',
        );

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function promoteReturnsUpdatedProfile(): void
    {
        $promotedProfile = $this->makeProfile(reputationScore: 600);

        $repService = $this->createStub(ReputationServiceInterface::class);
        $repService->method('addReputation')->willReturn($promotedProfile);
        $repService->method('getLevel')->willReturn(ReputationLevel::Expert);

        $controller = $this->makeController(repService: $repService);

        $response = $controller->promote(
            $this->makeRequest('POST', '/admin/forum/users/user-1/promote', [
                'points' => 100,
                'reason' => 'Community contribution',
            ]),
            'user-1',
        );

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(600, $body['data']['reputation_score']);
    }

    #[Test]
    public function promoteWithNegativePointsIsAccepted(): void
    {
        $demotedProfile = $this->makeProfile(reputationScore: 400);

        $repService = $this->createStub(ReputationServiceInterface::class);
        $repService->method('addReputation')->willReturn($demotedProfile);
        $repService->method('getLevel')->willReturn(ReputationLevel::Trusted);

        $controller = $this->makeController(repService: $repService);

        $response = $controller->promote(
            $this->makeRequest('POST', '/admin/forum/users/user-1/promote', [
                'points' => -100,
            ]),
            'user-1',
        );

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(400, $body['data']['reputation_score']);
    }

    #[Test]
    public function promoteServiceExceptionReturns422(): void
    {
        $repService = $this->createStub(ReputationServiceInterface::class);
        $repService->method('addReputation')->willThrowException(
            new ForumException('Profile not found'),
        );
        $repService->method('getLevel')->willReturn(ReputationLevel::Newcomer);

        $controller = $this->makeController(repService: $repService);

        $response = $controller->promote(
            $this->makeRequest('POST', '/admin/forum/users/user-1/promote', [
                'points' => 50,
            ]),
            'user-1',
        );

        self::assertSame(422, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Profile not found', $body['error']);
    }
}
