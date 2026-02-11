<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Event;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Domain\ReportStatus;
use Pulsar\Extension\Forum\Domain\ThreadType;
use Pulsar\Extension\Forum\Domain\VoteDirection;
use Pulsar\Extension\Forum\Event\BadgeAwarded;
use Pulsar\Extension\Forum\Event\PostAcceptedAsSolution;
use Pulsar\Extension\Forum\Event\PostCreated;
use Pulsar\Extension\Forum\Event\PostDeleted;
use Pulsar\Extension\Forum\Event\PostEdited;
use Pulsar\Extension\Forum\Event\ReportResolved;
use Pulsar\Extension\Forum\Event\ReportSubmitted;
use Pulsar\Extension\Forum\Event\ReputationChanged;
use Pulsar\Extension\Forum\Event\ThreadCreated;
use Pulsar\Extension\Forum\Event\ThreadDeleted;
use Pulsar\Extension\Forum\Event\ThreadLocked;
use Pulsar\Extension\Forum\Event\ThreadPinned;
use Pulsar\Extension\Forum\Event\ThreadUnlocked;
use Pulsar\Extension\Forum\Event\ThreadUnpinned;
use Pulsar\Extension\Forum\Event\UserBanned;
use Pulsar\Extension\Forum\Event\UserUnbanned;
use Pulsar\Extension\Forum\Event\VoteCast;
use Pulsar\Extension\Forum\Event\VoteRemoved;

#[CoversClass(BadgeAwarded::class)]
#[CoversClass(PostAcceptedAsSolution::class)]
#[CoversClass(PostCreated::class)]
#[CoversClass(PostDeleted::class)]
#[CoversClass(PostEdited::class)]
#[CoversClass(ReportResolved::class)]
#[CoversClass(ReportSubmitted::class)]
#[CoversClass(ReputationChanged::class)]
#[CoversClass(ThreadCreated::class)]
#[CoversClass(ThreadDeleted::class)]
#[CoversClass(ThreadLocked::class)]
#[CoversClass(ThreadPinned::class)]
#[CoversClass(ThreadUnlocked::class)]
#[CoversClass(ThreadUnpinned::class)]
#[CoversClass(UserBanned::class)]
#[CoversClass(UserUnbanned::class)]
#[CoversClass(VoteCast::class)]
#[CoversClass(VoteRemoved::class)]
final class EventDtoTest extends TestCase
{
    #[Test]
    public function badgeAwardedStoresAllProperties(): void
    {
        $event = new BadgeAwarded(
            badgeId: 'badge-1',
            userId: 'user-1',
            badge: 'helpful',
            tenantId: 'tenant-1',
        );

        self::assertSame('badge-1', $event->badgeId);
        self::assertSame('user-1', $event->userId);
        self::assertSame('helpful', $event->badge);
        self::assertSame('tenant-1', $event->tenantId);
    }

    #[Test]
    public function badgeAwardedDefaultsTenantIdToNull(): void
    {
        $event = new BadgeAwarded('b-1', 'u-1', 'badge');
        self::assertNull($event->tenantId);
    }

    #[Test]
    public function postCreatedStoresAllProperties(): void
    {
        $event = new PostCreated(
            postId: 'post-1',
            threadId: 'thread-1',
            authorId: 'author-1',
            tenantId: 'tenant-1',
            authorDisplayName: 'John',
        );

        self::assertSame('post-1', $event->postId);
        self::assertSame('thread-1', $event->threadId);
        self::assertSame('author-1', $event->authorId);
        self::assertSame('tenant-1', $event->tenantId);
        self::assertSame('John', $event->authorDisplayName);
    }

    #[Test]
    public function postCreatedDefaultsOptionalFields(): void
    {
        $event = new PostCreated('p-1', 't-1', 'a-1');

        self::assertNull($event->tenantId);
        self::assertSame('', $event->authorDisplayName);
    }

    #[Test]
    public function postDeletedStoresAllProperties(): void
    {
        $event = new PostDeleted('post-1', 'thread-1', 'mod-1', 'tenant-1');

        self::assertSame('post-1', $event->postId);
        self::assertSame('thread-1', $event->threadId);
        self::assertSame('mod-1', $event->deletedBy);
        self::assertSame('tenant-1', $event->tenantId);
    }

    #[Test]
    public function postEditedStoresAllProperties(): void
    {
        $event = new PostEdited('post-1', 'thread-1', 'editor-1', 'tenant-1');

        self::assertSame('post-1', $event->postId);
        self::assertSame('thread-1', $event->threadId);
        self::assertSame('editor-1', $event->editedBy);
        self::assertSame('tenant-1', $event->tenantId);
    }

    #[Test]
    public function postAcceptedAsSolutionStoresAllProperties(): void
    {
        $event = new PostAcceptedAsSolution('post-1', 'thread-1', 'helper-1', 'op-1', 'tenant-1');

        self::assertSame('post-1', $event->postId);
        self::assertSame('thread-1', $event->threadId);
        self::assertSame('helper-1', $event->postAuthorId);
        self::assertSame('op-1', $event->acceptedBy);
        self::assertSame('tenant-1', $event->tenantId);
    }

    #[Test]
    public function reportResolvedStoresAllProperties(): void
    {
        $event = new ReportResolved(
            reportId: 'r-1',
            targetType: 'thread',
            targetId: 'thread-1',
            moderatorId: 'mod-1',
            resolution: ReportStatus::Actioned,
            tenantId: 'tenant-1',
        );

        self::assertSame('r-1', $event->reportId);
        self::assertSame('thread', $event->targetType);
        self::assertSame('thread-1', $event->targetId);
        self::assertSame('mod-1', $event->moderatorId);
        self::assertSame(ReportStatus::Actioned, $event->resolution);
        self::assertSame('tenant-1', $event->tenantId);
    }

    #[Test]
    public function reportSubmittedStoresAllProperties(): void
    {
        $event = new ReportSubmitted(
            reportId: 'r-1',
            targetType: 'post',
            targetId: 'post-1',
            reporterId: 'reporter-1',
            reason: 'Spam',
            tenantId: 'tenant-1',
        );

        self::assertSame('r-1', $event->reportId);
        self::assertSame('post', $event->targetType);
        self::assertSame('post-1', $event->targetId);
        self::assertSame('reporter-1', $event->reporterId);
        self::assertSame('Spam', $event->reason);
        self::assertSame('tenant-1', $event->tenantId);
    }

    #[Test]
    public function reputationChangedStoresAllProperties(): void
    {
        $event = new ReputationChanged(
            userId: 'user-1',
            previousScore: 100,
            newScore: 110,
            delta: 10,
            reason: 'post_upvoted',
            tenantId: 'tenant-1',
        );

        self::assertSame('user-1', $event->userId);
        self::assertSame(100, $event->previousScore);
        self::assertSame(110, $event->newScore);
        self::assertSame(10, $event->delta);
        self::assertSame('post_upvoted', $event->reason);
        self::assertSame('tenant-1', $event->tenantId);
    }

    #[Test]
    public function threadCreatedStoresAllProperties(): void
    {
        $event = new ThreadCreated(
            threadId: 'thread-1',
            categoryId: 'cat-1',
            authorId: 'author-1',
            title: 'My Thread',
            type: ThreadType::Question,
            tenantId: 'tenant-1',
        );

        self::assertSame('thread-1', $event->threadId);
        self::assertSame('cat-1', $event->categoryId);
        self::assertSame('author-1', $event->authorId);
        self::assertSame('My Thread', $event->title);
        self::assertSame(ThreadType::Question, $event->type);
        self::assertSame('tenant-1', $event->tenantId);
    }

    #[Test]
    public function threadDeletedStoresAllProperties(): void
    {
        $event = new ThreadDeleted('thread-1', 'mod-1', 'tenant-1');

        self::assertSame('thread-1', $event->threadId);
        self::assertSame('mod-1', $event->deletedBy);
        self::assertSame('tenant-1', $event->tenantId);
    }

    #[Test]
    public function threadLockedStoresAllProperties(): void
    {
        $event = new ThreadLocked('thread-1', 'mod-1', 'tenant-1');

        self::assertSame('thread-1', $event->threadId);
        self::assertSame('mod-1', $event->lockedBy);
        self::assertSame('tenant-1', $event->tenantId);
    }

    #[Test]
    public function threadPinnedStoresAllProperties(): void
    {
        $event = new ThreadPinned('thread-1', 'mod-1', 'tenant-1');

        self::assertSame('thread-1', $event->threadId);
        self::assertSame('mod-1', $event->pinnedBy);
        self::assertSame('tenant-1', $event->tenantId);
    }

    #[Test]
    public function threadUnlockedStoresAllProperties(): void
    {
        $event = new ThreadUnlocked('thread-1', 'mod-1', 'tenant-1');

        self::assertSame('thread-1', $event->threadId);
        self::assertSame('mod-1', $event->unlockedBy);
        self::assertSame('tenant-1', $event->tenantId);
    }

    #[Test]
    public function threadUnpinnedStoresAllProperties(): void
    {
        $event = new ThreadUnpinned('thread-1', 'mod-1', 'tenant-1');

        self::assertSame('thread-1', $event->threadId);
        self::assertSame('mod-1', $event->unpinnedBy);
        self::assertSame('tenant-1', $event->tenantId);
    }

    #[Test]
    public function userBannedStoresAllProperties(): void
    {
        $expiresAt = new DateTimeImmutable('+7 days');
        $event = new UserBanned(
            userId: 'user-1',
            bannedBy: 'mod-1',
            reason: 'Spam',
            expiresAt: $expiresAt,
            tenantId: 'tenant-1',
        );

        self::assertSame('user-1', $event->userId);
        self::assertSame('mod-1', $event->bannedBy);
        self::assertSame('Spam', $event->reason);
        self::assertSame($expiresAt, $event->expiresAt);
        self::assertSame('tenant-1', $event->tenantId);
    }

    #[Test]
    public function userBannedDefaultsOptionalFields(): void
    {
        $event = new UserBanned('user-1', 'mod-1', 'Reason');

        self::assertNull($event->expiresAt);
        self::assertNull($event->tenantId);
    }

    #[Test]
    public function userUnbannedStoresAllProperties(): void
    {
        $event = new UserUnbanned('user-1', 'mod-1', 'tenant-1');

        self::assertSame('user-1', $event->userId);
        self::assertSame('mod-1', $event->unbannedBy);
        self::assertSame('tenant-1', $event->tenantId);
    }

    #[Test]
    public function voteCastStoresAllProperties(): void
    {
        $event = new VoteCast(
            voteId: 'v-1',
            targetType: 'post',
            targetId: 'post-1',
            voterId: 'voter-1',
            direction: VoteDirection::Up,
            targetAuthorId: 'author-1',
            tenantId: 'tenant-1',
        );

        self::assertSame('v-1', $event->voteId);
        self::assertSame('post', $event->targetType);
        self::assertSame('post-1', $event->targetId);
        self::assertSame('voter-1', $event->voterId);
        self::assertSame(VoteDirection::Up, $event->direction);
        self::assertSame('author-1', $event->targetAuthorId);
        self::assertSame('tenant-1', $event->tenantId);
    }

    #[Test]
    public function voteRemovedStoresAllProperties(): void
    {
        $event = new VoteRemoved(
            voteId: 'v-1',
            targetType: 'thread',
            targetId: 'thread-1',
            voterId: 'voter-1',
            previousDirection: VoteDirection::Down,
            targetAuthorId: 'author-1',
            tenantId: 'tenant-1',
        );

        self::assertSame('v-1', $event->voteId);
        self::assertSame('thread', $event->targetType);
        self::assertSame('thread-1', $event->targetId);
        self::assertSame('voter-1', $event->voterId);
        self::assertSame(VoteDirection::Down, $event->previousDirection);
        self::assertSame('author-1', $event->targetAuthorId);
        self::assertSame('tenant-1', $event->tenantId);
    }

    #[Test]
    #[DataProvider('tenantIdNullableEventProvider')]
    public function eventsDefaultTenantIdToNull(string $eventClass): void
    {
        $event = match ($eventClass) {
            BadgeAwarded::class => new BadgeAwarded('b-1', 'u-1', 'badge'),
            PostCreated::class => new PostCreated('p-1', 't-1', 'a-1'),
            PostDeleted::class => new PostDeleted('p-1', 't-1', 'd-1'),
            PostEdited::class => new PostEdited('p-1', 't-1', 'e-1'),
            PostAcceptedAsSolution::class => new PostAcceptedAsSolution('p-1', 't-1', 'pa-1', 'ab-1'),
            ReportResolved::class => new ReportResolved('r-1', 'thread', 't-1', 'm-1', ReportStatus::Dismissed),
            ReportSubmitted::class => new ReportSubmitted('r-1', 'post', 'p-1', 'r-1', 'reason'),
            ReputationChanged::class => new ReputationChanged('u-1', 0, 10, 10, 'reason'),
            ThreadCreated::class => new ThreadCreated('t-1', 'c-1', 'a-1', 'Title', ThreadType::Discussion),
            ThreadDeleted::class => new ThreadDeleted('t-1', 'd-1'),
            ThreadLocked::class => new ThreadLocked('t-1', 'l-1'),
            ThreadPinned::class => new ThreadPinned('t-1', 'p-1'),
            ThreadUnlocked::class => new ThreadUnlocked('t-1', 'u-1'),
            ThreadUnpinned::class => new ThreadUnpinned('t-1', 'u-1'),
            UserBanned::class => new UserBanned('u-1', 'm-1', 'reason'),
            UserUnbanned::class => new UserUnbanned('u-1', 'm-1'),
            VoteCast::class => new VoteCast('v-1', 'post', 'p-1', 'v-1', VoteDirection::Up, 'a-1'),
            VoteRemoved::class => new VoteRemoved('v-1', 'thread', 't-1', 'v-1', VoteDirection::Up, 'a-1'),
            default => throw new InvalidArgumentException("Unhandled event class: {$eventClass}"),
        };

        self::assertNull($event->tenantId);
    }

    /** @return iterable<string, array{string}> */
    public static function tenantIdNullableEventProvider(): iterable
    {
        yield 'BadgeAwarded' => [BadgeAwarded::class];
        yield 'PostCreated' => [PostCreated::class];
        yield 'PostDeleted' => [PostDeleted::class];
        yield 'PostEdited' => [PostEdited::class];
        yield 'PostAcceptedAsSolution' => [PostAcceptedAsSolution::class];
        yield 'ReportResolved' => [ReportResolved::class];
        yield 'ReportSubmitted' => [ReportSubmitted::class];
        yield 'ReputationChanged' => [ReputationChanged::class];
        yield 'ThreadCreated' => [ThreadCreated::class];
        yield 'ThreadDeleted' => [ThreadDeleted::class];
        yield 'ThreadLocked' => [ThreadLocked::class];
        yield 'ThreadPinned' => [ThreadPinned::class];
        yield 'ThreadUnlocked' => [ThreadUnlocked::class];
        yield 'ThreadUnpinned' => [ThreadUnpinned::class];
        yield 'UserBanned' => [UserBanned::class];
        yield 'UserUnbanned' => [UserUnbanned::class];
        yield 'VoteCast' => [VoteCast::class];
        yield 'VoteRemoved' => [VoteRemoved::class];
    }
}
