<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Internal\Service;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Extension\Forum\Domain\BanType;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Internal\Service\BanService;
use Pulsar\Extension\Forum\Profile\ForumProfile;
use Pulsar\Extension\Forum\Profile\ForumProfileRepositoryInterface;
use Pulsar\Extension\Forum\Report\ForumModerationLogRepositoryInterface;
use Pulsar\Extension\Forum\Report\UserBan;
use Pulsar\Extension\Forum\Report\UserBanRepositoryInterface;

#[CoversClass(BanService::class)]
final class BanServiceTest extends TestCase
{
    private UserBanRepositoryInterface&Stub $banRepo;
    private ForumProfileRepositoryInterface&Stub $profileRepo;
    private ForumModerationLogRepositoryInterface&Stub $modLogRepo;
    private EventDispatcherInterface&Stub $events;
    private BanService $service;

    protected function setUp(): void
    {
        $this->banRepo = $this->createStub(UserBanRepositoryInterface::class);
        $this->profileRepo = $this->createStub(ForumProfileRepositoryInterface::class);
        $this->modLogRepo = $this->createStub(ForumModerationLogRepositoryInterface::class);
        $this->events = $this->createStub(EventDispatcherInterface::class);

        $this->service = new BanService(
            $this->banRepo,
            $this->profileRepo,
            $this->modLogRepo,
            $this->events,
        );
    }

    #[Test]
    public function banCreatesTemporaryBanWithExpiry(): void
    {
        $expiresAt = new DateTimeImmutable('+7 days');
        $profile = self::unbannedProfile();

        $this->profileRepo->method('findByUser')->willReturn($profile);

        $ban = $this->service->ban(
            userId: 'user-001',
            bannedBy: 'mod-001',
            reason: 'Spam',
            type: BanType::Temporary,
            expiresAt: $expiresAt,
        );

        self::assertSame('user-001', $ban->userId);
        self::assertSame('mod-001', $ban->bannedBy);
        self::assertSame('Spam', $ban->reason);
        self::assertSame(BanType::Temporary, $ban->type);
        self::assertSame($expiresAt, $ban->expiresAt);
        self::assertNull($ban->revokedAt);
    }

    #[Test]
    public function banCreatesPermanentBanWithoutExpiry(): void
    {
        $profile = self::unbannedProfile();
        $this->profileRepo->method('findByUser')->willReturn($profile);

        $ban = $this->service->ban(
            userId: 'user-001',
            bannedBy: 'mod-001',
            reason: 'Repeated violations',
            type: BanType::Permanent,
        );

        self::assertSame(BanType::Permanent, $ban->type);
        self::assertNull($ban->expiresAt);
    }

    #[Test]
    public function banThrowsWhenProfileNotFound(): void
    {
        $this->profileRepo->method('findByUser')->willReturn(null);

        $this->expectException(ForumException::class);
        $this->expectExceptionMessageIsOrContains('ForumProfile not found');

        $this->service->ban('unknown-user', 'mod-001', 'reason', BanType::Permanent);
    }

    #[Test]
    public function banThrowsWhenUserAlreadyBanned(): void
    {
        $profile = self::bannedProfile();
        $this->profileRepo->method('findByUser')->willReturn($profile);

        $this->expectException(ForumException::class);
        $this->expectExceptionMessageIsOrContains('banned');

        $this->service->ban('user-001', 'mod-001', 'reason', BanType::Permanent);
    }

    #[Test]
    public function isCurrentlyBannedReturnsTrueForActiveBan(): void
    {
        $ban = new UserBan(
            id: 'ban-001',
            userId: 'user-001',
            bannedBy: 'mod-001',
            reason: 'Spam',
            type: BanType::Permanent,
            expiresAt: null,
            createdAt: new DateTimeImmutable(),
            revokedAt: null,
        );

        $this->banRepo->method('findActiveByUser')->willReturn($ban);

        self::assertTrue($this->service->isCurrentlyBanned('user-001'));
    }

    #[Test]
    public function isCurrentlyBannedReturnsFalseWhenNoBan(): void
    {
        $this->banRepo->method('findActiveByUser')->willReturn(null);

        self::assertFalse($this->service->isCurrentlyBanned('user-001'));
    }

    #[Test]
    public function isCurrentlyBannedReturnsFalseForExpiredBan(): void
    {
        $ban = new UserBan(
            id: 'ban-001',
            userId: 'user-001',
            bannedBy: 'mod-001',
            reason: 'Spam',
            type: BanType::Temporary,
            expiresAt: new DateTimeImmutable('-1 day'),
            createdAt: new DateTimeImmutable('-7 days'),
            revokedAt: null,
        );

        $this->banRepo->method('findActiveByUser')->willReturn($ban);

        self::assertFalse($this->service->isCurrentlyBanned('user-001'));
    }

    #[Test]
    public function unbanRevokesActiveBanAndUpdatesProfile(): void
    {
        $profile = self::bannedProfile();
        $ban = new UserBan(
            id: 'ban-001',
            userId: 'user-001',
            bannedBy: 'mod-001',
            reason: 'Spam',
            type: BanType::Permanent,
            expiresAt: null,
            createdAt: new DateTimeImmutable(),
            revokedAt: null,
        );

        /** @var UserBanRepositoryInterface&MockObject $banRepo */
        $banRepo = $this->createMock(UserBanRepositoryInterface::class);
        $banRepo->method('findActiveByUser')->willReturn($ban);
        $banRepo->expects(self::once())->method('save');

        /** @var ForumProfileRepositoryInterface&MockObject $profileRepo */
        $profileRepo = $this->createMock(ForumProfileRepositoryInterface::class);
        $profileRepo->method('findByUser')->willReturn($profile);
        $profileRepo->expects(self::once())->method('save');

        /** @var ForumModerationLogRepositoryInterface&MockObject $modLogRepo */
        $modLogRepo = $this->createMock(ForumModerationLogRepositoryInterface::class);
        $modLogRepo->expects(self::once())->method('save');

        $service = new BanService(
            $banRepo,
            $profileRepo,
            $modLogRepo,
            $this->events,
        );

        $service->unban('user-001', 'mod-002');
    }

    #[Test]
    public function unbanThrowsWhenProfileNotFound(): void
    {
        $this->profileRepo->method('findByUser')->willReturn(null);

        $this->expectException(ForumException::class);
        $this->expectExceptionMessageIsOrContains('ForumProfile not found');

        $this->service->unban('unknown-user', 'mod-001');
    }

    #[Test]
    public function getActiveBanReturnsNullWhenNoBanExists(): void
    {
        $this->banRepo->method('findActiveByUser')->willReturn(null);

        self::assertNull($this->service->getActiveBan('user-001'));
    }

    #[Test]
    public function getActiveBanReturnsBanWhenActive(): void
    {
        $ban = new UserBan(
            id: 'ban-001',
            userId: 'user-001',
            bannedBy: 'mod-001',
            reason: 'Spam',
            type: BanType::Permanent,
            expiresAt: null,
            createdAt: new DateTimeImmutable(),
            revokedAt: null,
        );

        $this->banRepo->method('findActiveByUser')->willReturn($ban);

        $result = $this->service->getActiveBan('user-001');

        self::assertNotNull($result);
        self::assertSame('ban-001', $result->id);
    }

    #[Test]
    public function getActiveBanReturnsNullForExpiredBan(): void
    {
        $ban = new UserBan(
            id: 'ban-001',
            userId: 'user-001',
            bannedBy: 'mod-001',
            reason: 'Spam',
            type: BanType::Temporary,
            expiresAt: new DateTimeImmutable('-1 hour'),
            createdAt: new DateTimeImmutable('-7 days'),
            revokedAt: null,
        );

        $this->banRepo->method('findActiveByUser')->willReturn($ban);

        self::assertNull($this->service->getActiveBan('user-001'));
    }

    private static function unbannedProfile(): ForumProfile
    {
        $now = new DateTimeImmutable();

        return new ForumProfile(
            id: 'profile-001',
            tenantId: 'tenant-001',
            userId: 'user-001',
            reputationScore: 50,
            postCount: 10,
            threadCount: 2,
            isBanned: false,
            banReason: null,
            bannedAt: null,
            banExpiresAt: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    private static function bannedProfile(): ForumProfile
    {
        $now = new DateTimeImmutable();

        return new ForumProfile(
            id: 'profile-001',
            tenantId: 'tenant-001',
            userId: 'user-001',
            reputationScore: 50,
            postCount: 10,
            threadCount: 2,
            isBanned: true,
            banReason: 'Spam',
            bannedAt: $now,
            banExpiresAt: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }
}
