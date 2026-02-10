<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Post;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Post\Post;

#[CoversClass(Post::class)]
final class PostTest extends TestCase
{
    #[Test]
    public function createReturnsPostWithDefaults(): void
    {
        $post = Post::create(
            id: 'post-1',
            threadId: 'thread-1',
            authorId: 'user-1',
            body: '# Hello',
            bodyHtml: '<h1>Hello</h1>',
            ipHash: 'hash-ip',
            userAgentHash: 'hash-ua',
        );

        self::assertSame('post-1', $post->id);
        self::assertNull($post->tenantId);
        self::assertSame('thread-1', $post->threadId);
        self::assertNull($post->parentId);
        self::assertSame('user-1', $post->authorId);
        self::assertSame('# Hello', $post->body);
        self::assertSame('<h1>Hello</h1>', $post->bodyHtml);
        self::assertFalse($post->isSolution);
        self::assertSame(0, $post->voteScore);
        self::assertSame(0, $post->editCount);
        self::assertNull($post->editedBy);
        self::assertNull($post->editedAt);
        self::assertNotNull($post->editWindowExpiresAt);
        self::assertNull($post->deletedAt);
        self::assertSame(1, $post->version);
    }

    #[Test]
    public function createWithParentId(): void
    {
        $post = Post::create(
            id: 'post-2',
            threadId: 'thread-1',
            authorId: 'user-1',
            body: 'Reply',
            bodyHtml: '<p>Reply</p>',
            ipHash: 'h',
            userAgentHash: 'h',
            parentId: 'post-1',
        );

        self::assertSame('post-1', $post->parentId);
    }

    #[Test]
    public function createWithZeroEditWindowHasNoExpiry(): void
    {
        $post = Post::create(
            id: 'post-1',
            threadId: 'thread-1',
            authorId: 'user-1',
            body: 'Body',
            bodyHtml: '<p>Body</p>',
            ipHash: 'h',
            userAgentHash: 'h',
            editWindowMinutes: 0,
        );

        self::assertNull($post->editWindowExpiresAt);
    }

    #[Test]
    public function canEditReturnsTrueWithinWindow(): void
    {
        $post = Post::create(
            id: 'post-1',
            threadId: 'thread-1',
            authorId: 'user-1',
            body: 'Body',
            bodyHtml: '<p>Body</p>',
            ipHash: 'h',
            userAgentHash: 'h',
            editWindowMinutes: 60,
        );

        self::assertTrue($post->canEdit());
    }

    #[Test]
    public function canEditReturnsTrueWhenNoEditWindow(): void
    {
        $post = Post::create(
            id: 'post-1',
            threadId: 'thread-1',
            authorId: 'user-1',
            body: 'Body',
            bodyHtml: '<p>Body</p>',
            ipHash: 'h',
            userAgentHash: 'h',
            editWindowMinutes: 0,
        );

        self::assertTrue($post->canEdit());
    }

    #[Test]
    public function editUpdatesBodyAndMetadata(): void
    {
        $post = Post::create(
            id: 'post-1',
            threadId: 'thread-1',
            authorId: 'user-1',
            body: 'Original',
            bodyHtml: '<p>Original</p>',
            ipHash: 'h',
            userAgentHash: 'h',
        );

        $edited = $post->edit('Updated', '<p>Updated</p>', 'editor-1');

        self::assertSame('Updated', $edited->body);
        self::assertSame('<p>Updated</p>', $edited->bodyHtml);
        self::assertSame(1, $edited->editCount);
        self::assertSame('editor-1', $edited->editedBy);
        self::assertNotNull($edited->editedAt);
    }

    #[Test]
    public function editThrowsWhenWindowExpired(): void
    {
        $now = new DateTimeImmutable();
        $past = $now->modify('-1 hour');

        $post = new Post(
            id: 'post-1',
            tenantId: null,
            threadId: 'thread-1',
            parentId: null,
            authorId: 'user-1',
            body: 'Body',
            bodyHtml: '<p>Body</p>',
            isSolution: false,
            voteScore: 0,
            editCount: 0,
            editedBy: null,
            ipHash: 'h',
            userAgentHash: 'h',
            editedAt: null,
            editWindowExpiresAt: $past,
            createdAt: $past,
            updatedAt: $past,
            deletedAt: null,
        );

        $this->expectException(ForumException::class);
        $this->expectExceptionMessage('Edit window has expired');

        $post->edit('New', '<p>New</p>', 'editor-1');
    }

    #[Test]
    public function markAsSolution(): void
    {
        $post = Post::create(
            id: 'post-1',
            threadId: 'thread-1',
            authorId: 'user-1',
            body: 'Answer',
            bodyHtml: '<p>Answer</p>',
            ipHash: 'h',
            userAgentHash: 'h',
        );

        $solution = $post->markAsSolution();

        self::assertTrue($solution->isSolution);
        self::assertFalse($post->isSolution);
    }

    #[Test]
    public function unmarkAsSolution(): void
    {
        $post = Post::create(
            id: 'post-1',
            threadId: 'thread-1',
            authorId: 'user-1',
            body: 'Answer',
            bodyHtml: '<p>Answer</p>',
            ipHash: 'h',
            userAgentHash: 'h',
        );

        $solution = $post->markAsSolution();
        $unmarked = $solution->unmarkAsSolution();

        self::assertFalse($unmarked->isSolution);
    }

    #[Test]
    public function updateVoteScore(): void
    {
        $post = Post::create(
            id: 'post-1',
            threadId: 'thread-1',
            authorId: 'user-1',
            body: 'Body',
            bodyHtml: '<p>Body</p>',
            ipHash: 'h',
            userAgentHash: 'h',
        );

        $upvoted = $post->updateVoteScore(3);
        self::assertSame(3, $upvoted->voteScore);

        $downvoted = $upvoted->updateVoteScore(-1);
        self::assertSame(2, $downvoted->voteScore);
    }

    #[Test]
    public function isDeleted(): void
    {
        $post = Post::create(
            id: 'post-1',
            threadId: 'thread-1',
            authorId: 'user-1',
            body: 'Body',
            bodyHtml: '<p>Body</p>',
            ipHash: 'h',
            userAgentHash: 'h',
        );

        self::assertFalse($post->isDeleted());
    }
}
