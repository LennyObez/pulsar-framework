<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Service;

use Pulsar\Api\Api;
use Pulsar\Extension\Forum\Domain\ThreadType;
use Pulsar\Extension\Forum\Post\Post;
use Pulsar\Extension\Forum\Thread\Thread;

/**
 * Primary forum service: thread and post CRUD with lifecycle operations.
 */
#[Api(since: '1.0.0')]
interface ForumServiceInterface
{
    public function createThread(
        string $categoryId,
        string $authorId,
        string $title,
        string $slug,
        ThreadType $type,
        string $body,
        string $bodyHtml,
        string $ipHash,
        string $userAgentHash,
        ?string $tenantId = null,
    ): Thread;

    public function createPost(
        string $threadId,
        string $authorId,
        string $body,
        string $bodyHtml,
        string $ipHash,
        string $userAgentHash,
        ?string $tenantId = null,
        ?string $parentId = null,
        string $authorDisplayName = '',
    ): Post;

    public function editPost(string $postId, string $newBody, string $newBodyHtml, string $editedBy, bool $isModerator = false): Post;

    public function deletePost(string $postId, string $deletedBy = ''): void;

    public function deleteThread(string $threadId, string $deletedBy = ''): void;

    public function acceptSolution(string $threadId, string $postId): Thread;

    public function lockThread(string $threadId, string $actorId = ''): Thread;

    public function unlockThread(string $threadId, string $actorId = ''): Thread;

    public function pinThread(string $threadId, string $actorId = ''): Thread;

    public function unpinThread(string $threadId, string $actorId = ''): Thread;
}
