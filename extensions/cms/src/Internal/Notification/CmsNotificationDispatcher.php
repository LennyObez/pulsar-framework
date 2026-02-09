<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Notification;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Content\Event\CommentReceived;
use Pulsar\Extension\Cms\Content\Event\ContentPublished;
use Pulsar\Extension\Cms\Content\Event\ReviewRequested;
use Pulsar\Extension\Cms\Notification\CmsNotificationInterface;
use Pulsar\Extension\Cms\Notification\CommentReceivedNotification;
use Pulsar\Extension\Cms\Notification\ContentPublishedNotification;
use Pulsar\Extension\Cms\Notification\ReviewRequestNotification;

/**
 * Listens to CMS workflow events and dispatches notifications.
 *
 * Actual delivery is delegated to the framework's notification infrastructure.
 * This dispatcher creates the appropriate CMS notification DTO and logs it.
 * Real channel delivery (email, database, etc.) is a framework-level concern
 * wired through NotificationManagerInterface when available.
 */
#[Internal(reason: 'CMS notification wiring — use CmsNotificationInterface for public API')]
final readonly class CmsNotificationDispatcher
{
    public function __construct(
        private CmsConfig $config,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public function dispatch(CmsNotificationInterface $notification): void
    {
        $this->logger->info('CMS notification dispatched', [
            'type' => $notification->type(),
            'subject' => $notification->subject(),
            'recipients' => $notification->recipientIds(),
            'channels' => $this->config->notifications->channels,
            'metadata' => $notification->metadata(),
        ]);
    }

    public function onContentPublished(ContentPublished $event): void
    {
        if (!$this->config->notifications->enabled || !$this->config->notifications->notifyOnPublish) {
            return;
        }

        $notification = new ContentPublishedNotification(
            contentId: $event->contentId,
            contentTitle: $event->contentId,
            authorId: $event->publishedBy,
            publishedAt: new DateTimeImmutable(),
            url: "/content/{$event->contentId}",
            recipientUserIds: [$event->publishedBy],
        );

        $this->dispatch($notification);
    }

    public function onReviewRequested(ReviewRequested $event): void
    {
        if (!$this->config->notifications->enabled || !$this->config->notifications->notifyOnReview) {
            return;
        }

        $reviewerIds = $event->reviewerId !== null ? [$event->reviewerId] : [];

        $notification = new ReviewRequestNotification(
            contentId: $event->contentId,
            contentTitle: $event->contentTitle,
            requesterId: $event->requesterId,
            reviewerIds: $reviewerIds,
            message: $event->message,
        );

        $this->dispatch($notification);
    }

    public function onCommentReceived(CommentReceived $event): void
    {
        if (!$this->config->notifications->enabled || !$this->config->notifications->notifyOnComment) {
            return;
        }

        $notification = new CommentReceivedNotification(
            contentId: $event->contentId,
            contentTitle: $event->contentTitle,
            commentId: $event->commentId,
            authorName: $event->authorName,
            commentBody: $event->commentBody,
        );

        $this->dispatch($notification);
    }
}
