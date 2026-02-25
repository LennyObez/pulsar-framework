<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Event;

use DateTimeImmutable;
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

final class EventTest extends TestCase
{
    #[Test]
    public function threadCreatedStoresAllProperties(): void
    {
        $event = new ThreadCreated(
            threadId: 'thread-1',
            categoryId: 'cat-1',
            authorId: 'user-1',
            title: 'Test Thread',
            type: ThreadType::Question,
            tenantId: 'tenant-1',
        );

        self::assertSame('thread-1', $event->threadId);
        self::assertSame('cat-1', $event->categoryId);
        self::assertSame('user-1', $event->authorId);
        self::assertSame('Test Thread', $event->title);
        self::assertSame(ThreadType::Question, $event->type);
        self::assertSame('tenant-1', $event->tenantId);
    }

    #[Test]
    public function threadCreatedDefaultsTenantToNull(): void
    {
        $event = new ThreadCreated('t', 'c', 'u', 'T', ThreadType::Discussion);

        self::assertNull($event->tenantId);
    }

    #[Test]
    public function postCreatedStoresAllProperties(): void
    {
        $event = new PostCreated(
            postId: 'post-1',
            threadId: 'thread-1',
            authorId: 'user-1',
            tenantId: 'tenant-1',
            authorDisplayName: 'Alice',
        );

        self::assertSame('post-1', $event->postId);
        self::assertSame('thread-1', $event->threadId);
        self::assertSame('user-1', $event->authorId);
        self::assertSame('tenant-1', $event->tenantId);
        self::assertSame('Alice', $event->authorDisplayName);
    }

    #[Test]
    public function postCreatedDefaultsOptionalFields(): void
    {
        $event = new PostCreated('p', 't', 'u');

        self::assertNull($event->tenantId);
        self::assertSame('', $event->authorDisplayName);
    }

    #[Test]
    public function postEditedStoresAllProperties(): void
    {
        $event = new PostEdited('post-1', 'thread-1', 'user-1', 'tenant-1');

        self::assertSame('post-1', $event->postId);
        self::assertSame('thread-1', $event->threadId);
        self::assertSame('user-1', $event->editedBy);
        self::assertSame('tenant-1', $event->tenantId);
    }

    #[Test]
    public function postDeletedStoresAllProperties(): void
    {
        $event = new PostDeleted('post-1', 'thread-1', 'mod-1');

        self::assertSame('post-1', $event->postId);
        self::assertSame('thread-1', $event->threadId);
        self::assertSame('mod-1', $event->deletedBy);
    }

    #[Test]
    public function postAcceptedAsSolutionStoresAllProperties(): void
    {
        $event = new PostAcceptedAsSolution('post-1', 'thread-1', 'author-1', 'acceptor-1');

        self::assertSame('post-1', $event->postId);
        self::assertSame('thread-1', $event->threadId);
        self::assertSame('author-1', $event->postAuthorId);
        self::assertSame('acceptor-1', $event->acceptedBy);
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
        $event = new ThreadLocked('thread-1', 'mod-1');

        self::assertSame('thread-1', $event->threadId);
        self::assertSame('mod-1', $event->lockedBy);
    }

    #[Test]
    public function threadUnlockedStoresAllProperties(): void
    {
        $event = new ThreadUnlocked('thread-1', 'mod-1');

        self::assertSame('thread-1', $event->threadId);
        self::assertSame('mod-1', $event->unlockedBy);
    }

    #[Test]
    public function threadPinnedStoresAllProperties(): void
    {
        $event = new ThreadPinned('thread-1', 'mod-1');

        self::assertSame('thread-1', $event->threadId);
        self::assertSame('mod-1', $event->pinnedBy);
    }

    #[Test]
    public function threadUnpinnedStoresAllProperties(): void
    {
        $event = new ThreadUnpinned('thread-1', 'mod-1');

        self::assertSame('thread-1', $event->threadId);
        self::assertSame('mod-1', $event->unpinnedBy);
    }

    #[Test]
    public function voteCastStoresAllProperties(): void
    {
        $event = new VoteCast('v-1', 'post', 'p-1', 'voter-1', VoteDirection::Up, 'author-1');

        self::assertSame('v-1', $event->voteId);
        self::assertSame('post', $event->targetType);
        self::assertSame('p-1', $event->targetId);
        self::assertSame('voter-1', $event->voterId);
        self::assertSame(VoteDirection::Up, $event->direction);
        self::assertSame('author-1', $event->targetAuthorId);
    }

    #[Test]
    public function voteRemovedStoresAllProperties(): void
    {
        $event = new VoteRemoved('v-1', 'thread', 't-1', 'voter-1', VoteDirection::Down, 'author-1');

        self::assertSame('v-1', $event->voteId);
        self::assertSame('thread', $event->targetType);
        self::assertSame('t-1', $event->targetId);
        self::assertSame('voter-1', $event->voterId);
        self::assertSame(VoteDirection::Down, $event->previousDirection);
        self::assertSame('author-1', $event->targetAuthorId);
    }

    #[Test]
    public function badgeAwardedStoresAllProperties(): void
    {
        $event = new BadgeAwarded('badge-1', 'user-1', 'first_post', 'tenant-1');

        self::assertSame('badge-1', $event->badgeId);
        self::assertSame('user-1', $event->userId);
        self::assertSame('first_post', $event->badge);
        self::assertSame('tenant-1', $event->tenantId);
    }

    #[Test]
    public function reputationChangedStoresAllProperties(): void
    {
        $event = new ReputationChanged('user-1', 10, 15, 5, 'upvote');

        self::assertSame('user-1', $event->userId);
        self::assertSame(10, $event->previousScore);
        self::assertSame(15, $event->newScore);
        self::assertSame(5, $event->delta);
        self::assertSame('upvote', $event->reason);
    }

    #[Test]
    public function reportSubmittedStoresAllProperties(): void
    {
        $event = new ReportSubmitted('r-1', 'post', 'p-1', 'u-1', 'Spam');

        self::assertSame('r-1', $event->reportId);
        self::assertSame('post', $event->targetType);
        self::assertSame('p-1', $event->targetId);
        self::assertSame('u-1', $event->reporterId);
        self::assertSame('Spam', $event->reason);
    }

    #[Test]
    public function reportResolvedStoresAllProperties(): void
    {
        $event = new ReportResolved('r-1', 'thread', 't-1', 'mod-1', ReportStatus::Actioned);

        self::assertSame('r-1', $event->reportId);
        self::assertSame('thread', $event->targetType);
        self::assertSame('t-1', $event->targetId);
        self::assertSame('mod-1', $event->moderatorId);
        self::assertSame(ReportStatus::Actioned, $event->resolution);
    }

    #[Test]
    public function userBannedStoresAllProperties(): void
    {
        $expires = new DateTimeImmutable('+7 days');
        $event = new UserBanned('user-1', 'mod-1', 'Spam', $expires, 'tenant-1');

        self::assertSame('user-1', $event->userId);
        self::assertSame('mod-1', $event->bannedBy);
        self::assertSame('Spam', $event->reason);
        self::assertSame($expires, $event->expiresAt);
        self::assertSame('tenant-1', $event->tenantId);
    }

    #[Test]
    public function userBannedDefaultsOptionalFields(): void
    {
        $event = new UserBanned('user-1', 'mod-1', 'Spam');

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
}
