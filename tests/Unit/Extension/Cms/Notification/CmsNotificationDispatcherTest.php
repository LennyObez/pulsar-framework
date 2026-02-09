<?php

declare(strict_types=1);

namespace Tests\Unit\Extension\Cms\Notification;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Config\NotificationConfig;
use Pulsar\Extension\Cms\Content\Event\CommentReceived;
use Pulsar\Extension\Cms\Content\Event\ContentPublished;
use Pulsar\Extension\Cms\Content\Event\ReviewRequested;
use Pulsar\Extension\Cms\Internal\Notification\CmsNotificationDispatcher;
use Pulsar\Extension\Cms\Notification\ContentPublishedNotification;

use function is_array;
use function is_string;

#[CoversClass(CmsNotificationDispatcher::class)]
final class CmsNotificationDispatcherTest extends TestCase
{
    #[Test]
    public function on_content_published_dispatches_notification(): void
    {
        $logger = $this->createStub(LoggerInterface::class);
        $dispatcher = new CmsNotificationDispatcher($this->enabledConfig(), $logger);

        $event = new ContentPublished(
            contentId: 'c-1',
            publishedBy: 'user-1',
            reason: null,
        );

        // Should not throw — dispatches via log channel
        $dispatcher->onContentPublished($event);

        // Verify by asserting the logger was called with expected args
        $loggerMock = $this->createMock(LoggerInterface::class);
        $loggerMock->expects(self::once())
            ->method('info')
            ->with(
                'CMS notification dispatched',
                self::callback(static function (array $context): bool {
                    return $context['type'] === 'content_published'
                        && is_string($context['subject'])
                        && str_contains($context['subject'], 'published');
                }),
            );

        $dispatcherWithMock = new CmsNotificationDispatcher($this->enabledConfig(), $loggerMock);
        $dispatcherWithMock->onContentPublished($event);
    }

    #[Test]
    public function on_review_requested_dispatches_notification(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with(
                'CMS notification dispatched',
                self::callback(static function (array $context): bool {
                    return $context['type'] === 'review_requested'
                        && is_string($context['subject'])
                        && str_contains($context['subject'], 'Review requested');
                }),
            );

        $dispatcher = new CmsNotificationDispatcher($this->enabledConfig(), $logger);

        $event = new ReviewRequested(
            contentId: 'c-1',
            requesterId: 'user-1',
            reviewerId: 'reviewer-1',
            contentTitle: 'Draft Post',
            message: 'Urgent review',
        );

        $dispatcher->onReviewRequested($event);
    }

    #[Test]
    public function on_comment_received_dispatches_notification(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with(
                'CMS notification dispatched',
                self::callback(static function (array $context): bool {
                    return $context['type'] === 'comment_received'
                        && is_string($context['subject'])
                        && str_contains($context['subject'], 'New comment');
                }),
            );

        $dispatcher = new CmsNotificationDispatcher($this->enabledConfig(), $logger);

        $event = new CommentReceived(
            contentId: 'c-1',
            contentTitle: 'My Article',
            commentId: 'comment-1',
            authorName: 'Jane Doe',
            commentBody: 'Great article!',
        );

        $dispatcher->onCommentReceived($event);
    }

    #[Test]
    public function on_content_published_skips_when_disabled(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('info');

        $dispatcher = new CmsNotificationDispatcher($this->disabledConfig(), $logger);

        $event = new ContentPublished(
            contentId: 'c-1',
            publishedBy: 'user-1',
            reason: null,
        );

        $dispatcher->onContentPublished($event);
    }

    #[Test]
    public function on_review_requested_skips_when_notify_on_review_is_false(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('info');

        $config = new CmsConfig(
            notifications: new NotificationConfig(enabled: true, notifyOnReview: false),
        );

        $dispatcher = new CmsNotificationDispatcher($config, $logger);

        $event = new ReviewRequested(
            contentId: 'c-1',
            requesterId: 'user-1',
            reviewerId: 'reviewer-1',
            contentTitle: 'Draft Post',
            message: null,
        );

        $dispatcher->onReviewRequested($event);
    }

    #[Test]
    public function on_comment_received_skips_when_notify_on_comment_is_false(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('info');

        $config = new CmsConfig(
            notifications: new NotificationConfig(enabled: true, notifyOnComment: false),
        );

        $dispatcher = new CmsNotificationDispatcher($config, $logger);

        $event = new CommentReceived(
            contentId: 'c-1',
            contentTitle: 'My Article',
            commentId: 'comment-1',
            authorName: 'Jane Doe',
            commentBody: 'Great article!',
        );

        $dispatcher->onCommentReceived($event);
    }

    #[Test]
    public function dispatch_logs_notification_with_metadata(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with(
                'CMS notification dispatched',
                self::callback(static function (array $context): bool {
                    return $context['type'] === 'content_published'
                        && $context['channels'] === ['log']
                        && is_array($context['recipients'])
                        && is_array($context['metadata']);
                }),
            );

        $dispatcher = new CmsNotificationDispatcher($this->enabledConfig(), $logger);

        $notification = new ContentPublishedNotification(
            contentId: 'c-1',
            contentTitle: 'Test',
            authorId: 'user-1',
            publishedAt: new DateTimeImmutable(),
            url: '/test',
            recipientUserIds: ['user-1'],
        );

        $dispatcher->dispatch($notification);
    }

    private function enabledConfig(): CmsConfig
    {
        return new CmsConfig(
            notifications: new NotificationConfig(enabled: true),
        );
    }

    private function disabledConfig(): CmsConfig
    {
        return new CmsConfig(
            notifications: new NotificationConfig(enabled: false),
        );
    }
}
