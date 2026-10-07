<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Internal\Service;

use DateTimeImmutable;
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

final class ReputationServiceTest extends TestCase
{
    private ForumProfileRepositoryInterface&Stub $profiles;
    private EventDispatcherInterface&Stub $events;
    private ReputationService $service;

    protected function setUp(): void
    {
        $this->profiles = $this->createStub(ForumProfileRepositoryInterface::class);
        $this->events = $this->createStub(EventDispatcherInterface::class);
        $this->service = new ReputationService($this->profiles, $this->events);
    }

    #[Test]
    public function addReputationThrowsWhenProfileNotFound(): void
    {
        $this->profiles->method('findByUser')->willReturn(null);

        $this->expectException(ForumException::class);
        $this->service->addReputation('unknown', 10, 'post_upvote');
    }

    #[Test]
    public function addReputationDispatchesEvent(): void
    {
        $profiles = $this->createMock(ForumProfileRepositoryInterface::class);
        $events = $this->createMock(EventDispatcherInterface::class);
        $service = new ReputationService($profiles, $events);

        $profile = ForumProfile::create('prof-1', 'user-1');
        $updatedProfile = new ForumProfile(
            id: 'prof-1',
            tenantId: null,
            userId: 'user-1',
            reputationScore: 10,
            postCount: 0,
            threadCount: 0,
            isBanned: false,
            banReason: null,
            bannedAt: null,
            banExpiresAt: null,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );

        $profiles->method('findByUser')
            ->willReturnOnConsecutiveCalls($profile, $updatedProfile);

        $profiles->expects($this->once())
            ->method('incrementReputation')
            ->with('user-1', 10, null);

        $events->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(ReputationChanged::class));

        $result = $service->addReputation('user-1', 10, 'post_upvote');

        self::assertSame('user-1', $result->userId);
    }

    #[Test]
    public function getLevelThrowsWhenProfileNotFound(): void
    {
        $this->profiles->method('findByUser')->willReturn(null);

        $this->expectException(ForumException::class);
        $this->service->getLevel('unknown');
    }

    #[Test]
    public function getLevelReturnsCorrectLevel(): void
    {
        $profile = ForumProfile::create('prof-1', 'user-1');
        $this->profiles->method('findByUser')->willReturn($profile);

        $level = $this->service->getLevel('user-1');

        self::assertInstanceOf(ReputationLevel::class, $level);
    }

    #[Test]
    public function isEligibleForPromotionReturnsFalseForUnknownUser(): void
    {
        $this->profiles->method('findByUser')->willReturn(null);

        self::assertFalse($this->service->isEligibleForPromotion('nobody'));
    }

    #[Test]
    public function getOrCreateProfileCreatesNewProfileIfMissing(): void
    {
        $profiles = $this->createMock(ForumProfileRepositoryInterface::class);
        $events = $this->createStub(EventDispatcherInterface::class);
        $service = new ReputationService($profiles, $events);

        $profiles->method('findByUser')->willReturn(null);
        $profiles->expects($this->once())->method('save');

        $profile = $service->getOrCreateProfile('new-user');

        self::assertSame('new-user', $profile->userId);
    }

    #[Test]
    public function getOrCreateProfileReturnsExistingProfile(): void
    {
        $profiles = $this->createMock(ForumProfileRepositoryInterface::class);
        $events = $this->createStub(EventDispatcherInterface::class);
        $service = new ReputationService($profiles, $events);

        $existing = ForumProfile::create('prof-1', 'existing-user');
        $profiles->method('findByUser')->willReturn($existing);
        $profiles->expects($this->never())->method('save');

        $profile = $service->getOrCreateProfile('existing-user');

        self::assertSame('existing-user', $profile->userId);
    }
}
