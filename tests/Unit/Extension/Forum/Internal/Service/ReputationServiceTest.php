<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Internal\Service;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Extension\Forum\Domain\ReputationLevel;
use Pulsar\Extension\Forum\Event\ReputationChanged;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Internal\Service\ReputationService;
use Pulsar\Extension\Forum\Profile\ForumProfile;
use Pulsar\Extension\Forum\Profile\ForumProfileRepositoryInterface;

#[CoversClass(ReputationService::class)]
final class ReputationServiceTest extends TestCase
{
    private ForumProfileRepositoryInterface&Stub $profiles;
    private EventDispatcherInterface&Stub $events;

    protected function setUp(): void
    {
        $this->profiles = $this->createStub(ForumProfileRepositoryInterface::class);
        $this->events = $this->createStub(EventDispatcherInterface::class);
    }

    private function makeService(
        ?ForumProfileRepositoryInterface $profiles = null,
        ?EventDispatcherInterface $events = null,
    ): ReputationService {
        return new ReputationService(
            profiles: $profiles ?? $this->profiles,
            events: $events ?? $this->events,
        );
    }

    private function makeProfile(string $userId = 'user-1', int $reputation = 0, ?string $tenantId = null): ForumProfile
    {
        return new ForumProfile(
            id: 'profile-1',
            tenantId: $tenantId,
            userId: $userId,
            reputationScore: $reputation,
            postCount: 0,
            threadCount: 0,
            isBanned: false,
            banReason: null,
            bannedAt: null,
            banExpiresAt: null,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );
    }

    #[Test]
    public function addReputationIncrementsAndDispatchesEvent(): void
    {
        $profile = $this->makeProfile(reputation: 10);
        $updatedProfile = $this->makeProfile(reputation: 15);

        $profiles = $this->createMock(ForumProfileRepositoryInterface::class);
        $events = $this->createMock(EventDispatcherInterface::class);

        $profiles->expects(self::exactly(2))->method('findByUser')
            ->with('user-1')
            ->willReturnOnConsecutiveCalls($profile, $updatedProfile);
        $profiles->expects(self::once())->method('incrementReputation')->with('user-1', 5, null);
        $events->expects(self::once())->method('dispatch')
            ->with(self::callback(function (ReputationChanged $event): bool {
                return $event->previousScore === 10
                    && $event->newScore === 15
                    && $event->delta === 5
                    && $event->reason === 'upvote';
            }));

        $service = $this->makeService(profiles: $profiles, events: $events);

        $result = $service->addReputation('user-1', 5, 'upvote');

        self::assertSame(15, $result->reputationScore);
    }

    #[Test]
    public function addReputationThrowsWhenProfileNotFound(): void
    {
        $this->profiles->method('findByUser')->willReturn(null);

        $service = $this->makeService();

        $this->expectException(ForumException::class);
        $this->expectExceptionMessage('ForumProfile not found');

        $service->addReputation('unknown', 5, 'reason');
    }

    #[Test]
    public function addReputationThrowsWhenProfileDisappearsAfterIncrement(): void
    {
        $profile = $this->makeProfile();

        $profiles = $this->createMock(ForumProfileRepositoryInterface::class);
        $profiles->expects(self::exactly(2))->method('findByUser')
            ->willReturnOnConsecutiveCalls($profile, null);
        $profiles->expects(self::once())->method('incrementReputation');

        $service = $this->makeService(profiles: $profiles);

        $this->expectException(ForumException::class);
        $this->expectExceptionMessage('ForumProfile not found');

        $service->addReputation('user-1', 5, 'reason');
    }

    #[Test]
    public function getLevelReturnsReputationLevel(): void
    {
        $profile = $this->makeProfile(reputation: 55);
        $this->profiles->method('findByUser')->willReturn($profile);

        $service = $this->makeService();

        $level = $service->getLevel('user-1');

        self::assertSame(ReputationLevel::Regular, $level);
    }

    #[Test]
    public function getLevelThrowsWhenProfileNotFound(): void
    {
        $this->profiles->method('findByUser')->willReturn(null);

        $service = $this->makeService();

        $this->expectException(ForumException::class);

        $service->getLevel('unknown');
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function promotionEligibilityProvider(): iterable
    {
        yield 'newcomer at 5, next=Contributor(10), not eligible' => [5, false];
        yield 'contributor at 10, next=Regular(50), not eligible' => [10, false];
        yield 'regular at 50, next=Trusted(100), not eligible' => [50, false];
        yield 'champion at 1000, highest level' => [1000, false];
    }

    #[Test]
    #[DataProvider('promotionEligibilityProvider')]
    public function isEligibleForPromotionReturnsCorrectResult(int $reputation, bool $expected): void
    {
        $profile = $this->makeProfile(reputation: $reputation);
        $this->profiles->method('findByUser')->willReturn($profile);

        $service = $this->makeService();

        self::assertSame($expected, $service->isEligibleForPromotion('user-1'));
    }

    #[Test]
    public function isEligibleForPromotionReturnsFalseWhenProfileNotFound(): void
    {
        $this->profiles->method('findByUser')->willReturn(null);

        $service = $this->makeService();

        self::assertFalse($service->isEligibleForPromotion('unknown'));
    }

    #[Test]
    public function getOrCreateProfileReturnsExistingProfile(): void
    {
        $existing = $this->makeProfile();

        $profiles = $this->createMock(ForumProfileRepositoryInterface::class);
        $profiles->method('findByUser')->willReturn($existing);
        $profiles->expects(self::never())->method('save');

        $service = $this->makeService(profiles: $profiles);

        $result = $service->getOrCreateProfile('user-1');

        self::assertSame($existing, $result);
    }

    #[Test]
    public function getOrCreateProfileCreatesNewWhenNoneExists(): void
    {
        $profiles = $this->createMock(ForumProfileRepositoryInterface::class);
        $profiles->method('findByUser')->willReturn(null);
        $profiles->expects(self::once())->method('save')->with(self::isInstanceOf(ForumProfile::class));

        $service = $this->makeService(profiles: $profiles);

        $result = $service->getOrCreateProfile('new-user', 'tenant-1');

        self::assertSame('new-user', $result->userId);
        self::assertSame('tenant-1', $result->tenantId);
        self::assertSame(0, $result->reputationScore);
    }
}
