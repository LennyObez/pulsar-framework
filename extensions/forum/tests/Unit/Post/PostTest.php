<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Post;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Post\Post;

final class PostTest extends TestCase
{
    #[Test]
    public function createSetsDefaults(): void
    {
        $post = Post::create(
            id: 'post-1',
            threadId: 'thread-1',
            authorId: 'user-1',
            body: '**Hello**',
            bodyHtml: '<strong>Hello</strong>',
            ipHash: 'ip-hash',
            userAgentHash: 'ua-hash',
        );

        self::assertSame('post-1', $post->id);
        self::assertNull($post->tenantId);
        self::assertSame('thread-1', $post->threadId);
        self::assertNull($post->parentId);
        self::assertSame('user-1', $post->authorId);
        self::assertSame('**Hello**', $post->body);
        self::assertSame('<strong>Hello</strong>', $post->bodyHtml);
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
            id: 'post-1',
            threadId: 'thread-1',
            authorId: 'user-1',
            body: 'reply',
            bodyHtml: '<p>reply</p>',
            ipHash: 'ip',
            userAgentHash: 'ua',
            parentId: 'parent-post-1',
        );

        self::assertSame('parent-post-1', $post->parentId);
    }

    #[Test]
    public function createWithTenantId(): void
    {
        $post = Post::create(
            id: 'post-1',
            threadId: 'thread-1',
            authorId: 'user-1',
            body: 'body',
            bodyHtml: '<p>body</p>',
            ipHash: 'ip',
            userAgentHash: 'ua',
            tenantId: 'tenant-1',
        );

        self::assertSame('tenant-1', $post->tenantId);
    }

    #[Test]
    public function createWithZeroEditWindowSetsNoExpiry(): void
    {
        $post = Post::create(
            id: 'post-1',
            threadId: 'thread-1',
            authorId: 'user-1',
            body: 'body',
            bodyHtml: '<p>body</p>',
            ipHash: 'ip',
            userAgentHash: 'ua',
            editWindowMinutes: 0,
        );

        self::assertNull($post->editWindowExpiresAt);
    }

    #[Test]
    public function editUpdatesBodyAndMetadata(): void
    {
        $post = Post::create(
            id: 'post-1',
            threadId: 'thread-1',
            authorId: 'user-1',
            body: 'original',
            bodyHtml: '<p>original</p>',
            ipHash: 'ip',
            userAgentHash: 'ua',
        );

        $edited = $post->edit('updated', '<p>updated</p>', 'user-1');

        self::assertSame('updated', $edited->body);
        self::assertSame('<p>updated</p>', $edited->bodyHtml);
        self::assertSame(1, $edited->editCount);
        self::assertSame('user-1', $edited->editedBy);
        self::assertNotNull($edited->editedAt);
    }

    #[Test]
    public function editThrowsWhenWindowExpired(): void
    {
        $post = new Post(
            id: 'post-1',
            tenantId: null,
            threadId: 'thread-1',
            parentId: null,
            authorId: 'user-1',
            body: 'old',
            bodyHtml: '<p>old</p>',
            isSolution: false,
            voteScore: 0,
            editCount: 0,
            editedBy: null,
            ipHash: 'ip',
            userAgentHash: 'ua',
            editedAt: null,
            editWindowExpiresAt: new DateTimeImmutable('-1 hour'),
            createdAt: new DateTimeImmutable('-2 hours'),
            updatedAt: new DateTimeImmutable('-2 hours'),
            deletedAt: null,
        );

        $this->expectException(ForumException::class);
        $this->expectExceptionMessage('Edit window has expired');
        $post->edit('new', '<p>new</p>', 'user-1');
    }

    #[Test]
    public function canEditReturnsTrueWithinWindow(): void
    {
        $post = Post::create(
            id: 'post-1',
            threadId: 'thread-1',
            authorId: 'user-1',
            body: 'body',
            bodyHtml: '<p>body</p>',
            ipHash: 'ip',
            userAgentHash: 'ua',
        );

        self::assertTrue($post->canEdit());
    }

    #[Test]
    public function canEditReturnsTrueWithNullWindow(): void
    {
        $post = Post::create(
            id: 'post-1',
            threadId: 'thread-1',
            authorId: 'user-1',
            body: 'body',
            bodyHtml: '<p>body</p>',
            ipHash: 'ip',
            userAgentHash: 'ua',
            editWindowMinutes: 0,
        );

        self::assertTrue($post->canEdit());
    }

    #[Test]
    public function markAsSolutionSetsFlag(): void
    {
        $post = $this->createPost();
        $solution = $post->markAsSolution();

        self::assertTrue($solution->isSolution);
        self::assertFalse($post->isSolution);
    }

    #[Test]
    public function unmarkAsSolutionClearsFlag(): void
    {
        $post = $this->createPost();
        $solution = $post->markAsSolution();
        $unmarked = $solution->unmarkAsSolution();

        self::assertFalse($unmarked->isSolution);
    }

    #[Test]
    public function updateVoteScore(): void
    {
        $post = $this->createPost();

        $upvoted = $post->updateVoteScore(1);
        self::assertSame(1, $upvoted->voteScore);

        $downvoted = $upvoted->updateVoteScore(-3);
        self::assertSame(-2, $downvoted->voteScore);
    }

    #[Test]
    public function isDeletedReturnsFalseByDefault(): void
    {
        $post = $this->createPost();
        self::assertFalse($post->isDeleted());
    }

    #[Test]
    public function isDeletedReturnsTrueWhenDeletedAtSet(): void
    {
        $post = new Post(
            id: 'p',
            tenantId: null,
            threadId: 't',
            parentId: null,
            authorId: 'u',
            body: 'b',
            bodyHtml: '<p>b</p>',
            isSolution: false,
            voteScore: 0,
            editCount: 0,
            editedBy: null,
            ipHash: 'ip',
            userAgentHash: 'ua',
            editedAt: null,
            editWindowExpiresAt: null,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
            deletedAt: new DateTimeImmutable(),
        );

        self::assertTrue($post->isDeleted());
    }

    private function createPost(): Post
    {
        return Post::create(
            id: 'post-1',
            threadId: 'thread-1',
            authorId: 'user-1',
            body: 'Body text',
            bodyHtml: '<p>Body text</p>',
            ipHash: 'ip-hash',
            userAgentHash: 'ua-hash',
        );
    }
}
