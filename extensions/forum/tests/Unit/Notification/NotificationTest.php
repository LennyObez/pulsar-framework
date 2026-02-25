<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Notification;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Notification\ForumNotificationInterface;
use Pulsar\Extension\Forum\Notification\MentionNotification;
use Pulsar\Extension\Forum\Notification\ModerationActionNotification;
use Pulsar\Extension\Forum\Notification\PostUpvotedNotification;
use Pulsar\Extension\Forum\Notification\SolutionAcceptedNotification;
use Pulsar\Extension\Forum\Notification\ThreadReplyNotification;

final class NotificationTest extends TestCase
{
    #[Test]
    public function threadReplyNotificationProperties(): void
    {
        $notification = new ThreadReplyNotification(
            threadId: 'thread-1',
            threadTitle: 'Test Thread',
            postId: 'post-1',
            authorId: 'user-1',
            authorName: 'Alice',
            recipientUserIds: ['user-2', 'user-3'],
        );

        self::assertInstanceOf(ForumNotificationInterface::class, $notification);
        self::assertSame('thread_reply', $notification->type());
        self::assertSame(['user-2', 'user-3'], $notification->recipientIds());
        self::assertStringContainsString('Test Thread', $notification->subject());
        self::assertStringContainsString('Alice', $notification->body());
        self::assertSame('thread-1', $notification->metadata()['thread_id']);
        self::assertSame('post-1', $notification->metadata()['post_id']);
        self::assertSame('user-1', $notification->metadata()['author_id']);
    }

    #[Test]
    public function mentionNotificationProperties(): void
    {
        $notification = new MentionNotification(
            postId: 'post-1',
            threadId: 'thread-1',
            threadTitle: 'My Thread',
            mentionedUserId: 'user-2',
            authorId: 'user-1',
            authorName: 'Bob',
        );

        self::assertInstanceOf(ForumNotificationInterface::class, $notification);
        self::assertSame('mention', $notification->type());
        self::assertSame(['user-2'], $notification->recipientIds());
        self::assertStringContainsString('Bob', $notification->subject());
        self::assertStringContainsString('My Thread', $notification->subject());
        self::assertStringContainsString('mentioned you', $notification->body());
        self::assertSame('post-1', $notification->metadata()['post_id']);
        self::assertSame('thread-1', $notification->metadata()['thread_id']);
    }

    #[Test]
    public function postUpvotedNotificationProperties(): void
    {
        $notification = new PostUpvotedNotification(
            postId: 'post-1',
            threadId: 'thread-1',
            threadTitle: 'Question Thread',
            postAuthorId: 'user-1',
            voterId: 'user-2',
        );

        self::assertInstanceOf(ForumNotificationInterface::class, $notification);
        self::assertSame('post_upvoted', $notification->type());
        self::assertSame(['user-1'], $notification->recipientIds());
        self::assertStringContainsString('upvoted', $notification->subject());
        self::assertStringContainsString('Question Thread', $notification->subject());
        self::assertSame('user-2', $notification->metadata()['voter_id']);
    }

    #[Test]
    public function solutionAcceptedNotificationProperties(): void
    {
        $notification = new SolutionAcceptedNotification(
            postId: 'post-1',
            threadId: 'thread-1',
            threadTitle: 'How to X?',
            postAuthorId: 'user-1',
            acceptedBy: 'user-2',
        );

        self::assertInstanceOf(ForumNotificationInterface::class, $notification);
        self::assertSame('solution_accepted', $notification->type());
        self::assertSame(['user-1'], $notification->recipientIds());
        self::assertStringContainsString('accepted', $notification->subject());
        self::assertStringContainsString('How to X?', $notification->subject());
        self::assertSame('user-2', $notification->metadata()['accepted_by']);
    }

    #[Test]
    public function moderationActionNotificationProperties(): void
    {
        $notification = new ModerationActionNotification(
            targetType: 'post',
            targetId: 'post-1',
            targetAuthorId: 'user-1',
            moderatorId: 'mod-1',
            action: 'deleted',
            reason: 'Violation of rules',
        );

        self::assertInstanceOf(ForumNotificationInterface::class, $notification);
        self::assertSame('moderation_action', $notification->type());
        self::assertSame(['user-1'], $notification->recipientIds());
        self::assertStringContainsString('post', $notification->subject());
        self::assertStringContainsString('deleted', $notification->body());
        self::assertStringContainsString('Violation of rules', $notification->body());
        self::assertSame('post', $notification->metadata()['target_type']);
        self::assertSame('post-1', $notification->metadata()['target_id']);
        self::assertSame('mod-1', $notification->metadata()['moderator_id']);
    }
}
