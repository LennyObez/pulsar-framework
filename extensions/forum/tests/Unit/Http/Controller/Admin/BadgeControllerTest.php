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
use Pulsar\Extension\Forum\Badge\BadgeServiceInterface;
use Pulsar\Extension\Forum\Badge\UserBadge;
use Pulsar\Extension\Forum\Domain\Badge;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Http\Controller\Admin\BadgeController;
use Pulsar\Http\Message\ServerRequest;

use function count;

#[CoversClass(BadgeController::class)]
final class BadgeControllerTest extends TestCase
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

    private function makeUserBadge(
        string $id = 'ub-1',
        string $userId = 'user-1',
        Badge $badge = Badge::FirstPost,
    ): UserBadge {
        return new UserBadge(
            id: $id,
            tenantId: null,
            userId: $userId,
            badge: $badge,
            awardedAt: new DateTimeImmutable('2025-06-01 10:00:00'),
        );
    }

    private function makeRequest(
        string $method = 'GET',
        string $uri = '/admin/forum/badges',
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
    public function indexWithoutPermissionThrows(): void
    {
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $controller = new BadgeController(
            $this->createStub(BadgeServiceInterface::class),
            $gate,
        );

        $request = new ServerRequest(
            method: 'GET',
            uri: '/admin/forum/badges',
            headers: ['Accept' => 'application/json'],
        )->withAttribute('identity', $this->makeIdentity());

        $this->expectException(AuthorizationException::class);

        $controller->index($request);
    }

    #[Test]
    public function indexReturnsAllBadgeCases(): void
    {
        $controller = new BadgeController(
            $this->createStub(BadgeServiceInterface::class),
            $this->makeAllowGate(),
        );

        $response = $controller->index($this->makeRequest());

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);

        self::assertArrayHasKey('badges', $body);
        self::assertCount(count(Badge::cases()), $body['badges']);

        // Verify first badge structure
        self::assertSame('first_post', $body['badges'][0]['value']);
        self::assertSame('FirstPost', $body['badges'][0]['name']);
    }

    #[Test]
    public function userBadgesReturnsEmptyForNewUser(): void
    {
        $badgeService = $this->createStub(BadgeServiceInterface::class);
        $badgeService->method('getUserBadges')->willReturn([]);

        $controller = new BadgeController($badgeService, $this->makeAllowGate());

        $response = $controller->userBadges($this->makeRequest(), 'user-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('user-1', $body['user_id']);
        self::assertCount(0, $body['badges']);
    }

    #[Test]
    public function userBadgesReturnsAwardedBadges(): void
    {
        $userBadge = $this->makeUserBadge();

        $badgeService = $this->createStub(BadgeServiceInterface::class);
        $badgeService->method('getUserBadges')->willReturn([$userBadge]);

        $controller = new BadgeController($badgeService, $this->makeAllowGate());

        $response = $controller->userBadges($this->makeRequest(), 'user-1');

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertCount(1, $body['badges']);
        self::assertSame('ub-1', $body['badges'][0]['id']);
        self::assertSame('first_post', $body['badges'][0]['badge']);
        self::assertArrayHasKey('awarded_at', $body['badges'][0]);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function missingAwardFieldsProvider(): iterable
    {
        yield 'both missing' => [[]];
        yield 'user_id empty' => [['user_id' => '', 'badge' => 'first_post']];
        yield 'badge empty' => [['user_id' => 'user-1', 'badge' => '']];
        yield 'both empty' => [['user_id' => '', 'badge' => '']];
    }

    #[Test]
    #[DataProvider('missingAwardFieldsProvider')]
    public function awardWithMissingFieldsReturns422(array $body): void
    {
        $controller = new BadgeController(
            $this->createStub(BadgeServiceInterface::class),
            $this->makeAllowGate(),
        );

        $response = $controller->award($this->makeRequest('POST', '/admin/forum/badges/award', $body));

        self::assertSame(422, $response->getStatusCode());

        /** @var array<string, mixed> $responseBody */
        $responseBody = json_decode((string) $response->getBody(), true);
        self::assertSame('user_id and badge are required', $responseBody['error']);
    }

    #[Test]
    public function awardWithInvalidBadgeTypeReturns422(): void
    {
        $controller = new BadgeController(
            $this->createStub(BadgeServiceInterface::class),
            $this->makeAllowGate(),
        );

        $response = $controller->award(
            $this->makeRequest('POST', '/admin/forum/badges/award', [
                'user_id' => 'user-1',
                'badge' => 'nonexistent_badge',
            ]),
        );

        self::assertSame(422, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Invalid badge type', $body['error']);
    }

    #[Test]
    public function awardReturns201OnSuccess(): void
    {
        $userBadge = $this->makeUserBadge();

        $badgeService = $this->createStub(BadgeServiceInterface::class);
        $badgeService->method('award')->willReturn($userBadge);

        $controller = new BadgeController($badgeService, $this->makeAllowGate());

        $response = $controller->award(
            $this->makeRequest('POST', '/admin/forum/badges/award', [
                'user_id' => 'user-1',
                'badge' => 'first_post',
            ]),
        );

        self::assertSame(201, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('ub-1', $body['data']['id']);
        self::assertSame('user-1', $body['data']['user_id']);
        self::assertSame('first_post', $body['data']['badge']);
    }

    #[Test]
    public function awardReturnsAlreadyAwardedWhenServiceReturnsNull(): void
    {
        $badgeService = $this->createStub(BadgeServiceInterface::class);
        $badgeService->method('award')->willReturn(null);

        $controller = new BadgeController($badgeService, $this->makeAllowGate());

        $response = $controller->award(
            $this->makeRequest('POST', '/admin/forum/badges/award', [
                'user_id' => 'user-1',
                'badge' => 'first_post',
            ]),
        );

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('already_awarded', $body['data']['status']);
        self::assertSame('user-1', $body['data']['user_id']);
    }

    #[Test]
    public function revokeWithMissingFieldsReturns422(): void
    {
        $controller = new BadgeController(
            $this->createStub(BadgeServiceInterface::class),
            $this->makeAllowGate(),
        );

        $response = $controller->revoke(
            $this->makeRequest('POST', '/admin/forum/badges/revoke', []),
        );

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function revokeWithInvalidBadgeReturns422(): void
    {
        $controller = new BadgeController(
            $this->createStub(BadgeServiceInterface::class),
            $this->makeAllowGate(),
        );

        $response = $controller->revoke(
            $this->makeRequest('POST', '/admin/forum/badges/revoke', [
                'user_id' => 'user-1',
                'badge' => 'nonexistent',
            ]),
        );

        self::assertSame(422, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Invalid badge type', $body['error']);
    }

    #[Test]
    public function revokeReturnsSuccessOnValidRevoke(): void
    {
        $badgeService = $this->createStub(BadgeServiceInterface::class);

        $controller = new BadgeController($badgeService, $this->makeAllowGate());

        $response = $controller->revoke(
            $this->makeRequest('POST', '/admin/forum/badges/revoke', [
                'user_id' => 'user-1',
                'badge' => 'first_post',
            ]),
        );

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('revoked', $body['data']['status']);
        self::assertSame('user-1', $body['data']['user_id']);
    }

    #[Test]
    public function revokeServiceExceptionReturns422(): void
    {
        $badgeService = $this->createStub(BadgeServiceInterface::class);
        $badgeService->method('revoke')->willThrowException(
            new ForumException('Badge not found for user'),
        );

        $controller = new BadgeController($badgeService, $this->makeAllowGate());

        $response = $controller->revoke(
            $this->makeRequest('POST', '/admin/forum/badges/revoke', [
                'user_id' => 'user-1',
                'badge' => 'first_post',
            ]),
        );

        self::assertSame(422, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Badge not found for user', $body['error']);
    }
}
