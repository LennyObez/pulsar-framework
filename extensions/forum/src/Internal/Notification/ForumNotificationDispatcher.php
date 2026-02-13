<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Internal\Notification;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Pulsar\Api\Internal;
use Pulsar\Extension\Forum\Event\PostAcceptedAsSolution;
use Pulsar\Extension\Forum\Event\PostCreated;
use Pulsar\Extension\Forum\Event\ReportSubmitted;
use Pulsar\Extension\Forum\Event\VoteCast;
use Pulsar\Extension\Forum\Notification\ForumNotificationInterface;
use Pulsar\Extension\Forum\Notification\ModerationActionNotification;
use Pulsar\Extension\Forum\Notification\PostUpvotedNotification;
use Pulsar\Extension\Forum\Notification\SolutionAcceptedNotification;
use Pulsar\Extension\Forum\Notification\ThreadReplyNotification;
use Pulsar\Extension\Forum\Post\PostRepositoryInterface;
use Pulsar\Extension\Forum\Subscription\ThreadSubscriptionRepositoryInterface;
use Pulsar\Extension\Forum\Thread\Thread;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;

/**
 * Listens to forum domain events and dispatches notifications.
 *
 * Actual delivery is delegated to the framework's notification infrastructure.
 * This dispatcher creates the appropriate forum notification DTO and logs it.
 */
#[Internal(reason: 'Forum notification wiring — use ForumNotificationInterface for public API')]
final readonly class ForumNotificationDispatcher
{
    public function __construct(
        private ThreadRepositoryInterface $threadRepository,
        private ThreadSubscriptionRepositoryInterface $subscriptionRepository,
        private ?PostRepositoryInterface $postRepository = null,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public function dispatch(ForumNotificationInterface $notification): void
    {
        $this->logger->info('Forum notification dispatched', [
            'type' => $notification->type(),
            'subject' => $notification->subject(),
            'recipients' => $notification->recipientIds(),
            'metadata' => $notification->metadata(),
        ]);
    }

    public function onPostCreated(PostCreated $event): void
    {
        $thread = $this->threadRepository->findById($event->threadId);

        if ($thread === null) {
            return;
        }

        $subscriptions = $this->subscriptionRepository->findByThread($event->threadId);
        $recipientIds = [];

        foreach ($subscriptions as $subscription) {
            // Don't notify the post author about their own reply
            if ($subscription->userId !== $event->authorId) {
                $recipientIds[] = $subscription->userId;
            }
        }

        if ($recipientIds === []) {
            return;
        }

        $authorName = $event->authorDisplayName !== '' ? $event->authorDisplayName : $event->authorId;

        $notification = new ThreadReplyNotification(
            threadId: $event->threadId,
            threadTitle: $thread->title,
            postId: $event->postId,
            authorId: $event->authorId,
            authorName: $authorName,
            recipientUserIds: $recipientIds,
        );

        $this->dispatch($notification);
    }

    public function onVoteCast(VoteCast $event): void
    {
        if ($event->targetType !== 'post' || $event->direction->value !== 1) {
            return;
        }

        $thread = $this->findThreadForTarget($event->targetType, $event->targetId);

        if ($thread === null) {
            return;
        }

        // Don't notify if the voter is the post author
        if ($event->voterId === $event->targetAuthorId) {
            return;
        }

        $notification = new PostUpvotedNotification(
            postId: $event->targetId,
            threadId: $thread->id,
            threadTitle: $thread->title,
            postAuthorId: $event->targetAuthorId,
            voterId: $event->voterId,
        );

        $this->dispatch($notification);
    }

    public function onPostAcceptedAsSolution(PostAcceptedAsSolution $event): void
    {
        $thread = $this->threadRepository->findById($event->threadId);

        if ($thread === null) {
            return;
        }

        // Don't notify if the thread author accepted their own post
        if ($event->acceptedBy === $event->postAuthorId) {
            return;
        }

        $notification = new SolutionAcceptedNotification(
            postId: $event->postId,
            threadId: $event->threadId,
            threadTitle: $thread->title,
            postAuthorId: $event->postAuthorId,
            acceptedBy: $event->acceptedBy,
        );

        $this->dispatch($notification);
    }

    public function onReportSubmitted(ReportSubmitted $event): void
    {
        $notification = new ModerationActionNotification(
            targetType: $event->targetType,
            targetId: $event->targetId,
            targetAuthorId: $event->reporterId,
            moderatorId: '',
            action: 'report_submitted',
            reason: $event->reason,
        );

        $this->logger->info('Forum report submitted', [
            'type' => $notification->type(),
            'target_type' => $event->targetType,
            'target_id' => $event->targetId,
            'reporter_id' => $event->reporterId,
        ]);
    }

    private function findThreadForTarget(string $targetType, string $targetId): ?Thread
    {
        if ($targetType === 'thread') {
            return $this->threadRepository->findById($targetId);
        }

        if ($targetType === 'post' && $this->postRepository !== null) {
            $post = $this->postRepository->findById($targetId);

            if ($post !== null) {
                return $this->threadRepository->findById($post->threadId);
            }
        }

        return null;
    }
}
