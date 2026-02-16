<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Internal\Service;

use Pulsar\Api\Internal;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Extension\Forum\Badge\BadgeServiceInterface;
use Pulsar\Extension\Forum\Category\CategoryRepositoryInterface;
use Pulsar\Extension\Forum\Config\ForumConfig;
use Pulsar\Extension\Forum\Domain\Badge;
use Pulsar\Extension\Forum\Domain\ThreadType;
use Pulsar\Extension\Forum\Event\PostAcceptedAsSolution;
use Pulsar\Extension\Forum\Event\PostCreated;
use Pulsar\Extension\Forum\Event\PostDeleted;
use Pulsar\Extension\Forum\Event\PostEdited;
use Pulsar\Extension\Forum\Event\ThreadCreated;
use Pulsar\Extension\Forum\Event\ThreadDeleted;
use Pulsar\Extension\Forum\Event\ThreadLocked;
use Pulsar\Extension\Forum\Event\ThreadPinned;
use Pulsar\Extension\Forum\Event\ThreadUnlocked;
use Pulsar\Extension\Forum\Event\ThreadUnpinned;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Post\Post;
use Pulsar\Extension\Forum\Post\PostRepositoryInterface;
use Pulsar\Extension\Forum\Profile\ForumProfileRepositoryInterface;
use Pulsar\Extension\Forum\Service\ForumServiceInterface;
use Pulsar\Extension\Forum\Service\ReputationServiceInterface;
use Pulsar\Extension\Forum\Support\UuidGenerator;
use Pulsar\Extension\Forum\Thread\Thread;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;

/**
 * Core forum service handling thread and post lifecycle.
 */
#[Internal(reason: 'Use ForumServiceInterface for public API')]
final readonly class ForumService implements ForumServiceInterface
{
    public function __construct(
        private ThreadRepositoryInterface $threads,
        private PostRepositoryInterface $posts,
        private ForumProfileRepositoryInterface $profiles,
        private ReputationServiceInterface $reputationService,
        private BadgeServiceInterface $badgeService,
        private EventDispatcherInterface $events,
        private ForumConfig $config,
        private ?CategoryRepositoryInterface $categories = null,
    ) {}

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
    ): Thread {
        if ($this->categories !== null) {
            $category = $this->categories->findById($categoryId);

            if ($category === null) {
                throw ForumException::notFound('Category', $categoryId);
            }

            if ($category->isLocked) {
                throw ForumException::categoryLocked($categoryId);
            }
        }

        $threadId = UuidGenerator::v7();

        $thread = Thread::create(
            id: $threadId,
            categoryId: $categoryId,
            authorId: $authorId,
            title: $title,
            slug: $slug,
            type: $type,
            ipHash: $ipHash,
            userAgentHash: $userAgentHash,
            tenantId: $tenantId,
        );

        $this->threads->save($thread);

        // Create the opening post for the thread
        $post = Post::create(
            id: UuidGenerator::v7(),
            threadId: $threadId,
            authorId: $authorId,
            body: $body,
            bodyHtml: $bodyHtml,
            ipHash: $ipHash,
            userAgentHash: $userAgentHash,
            tenantId: $tenantId,
            editWindowMinutes: $this->config->editWindowMinutes,
        );

        $this->posts->save($post);

        // Ensure the author has a profile
        $this->reputationService->getOrCreateProfile($authorId, $tenantId);

        // C-2: Atomic thread count increment
        $this->profiles->incrementThreadCount($authorId, $tenantId);

        // Award reputation for creating a thread
        $this->reputationService->addReputation(
            $authorId,
            $this->config->reputation->pointsPerThread,
            'created_thread',
        );

        $this->events->dispatch(new ThreadCreated(
            threadId: $thread->id,
            categoryId: $thread->categoryId,
            authorId: $thread->authorId,
            title: $thread->title,
            type: $thread->type,
            tenantId: $thread->tenantId,
        ));

        // Evaluate the FirstPost badge
        $this->badgeService->award($authorId, Badge::FirstPost, $tenantId);

        return $thread;
    }

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
    ): Post {
        $thread = $this->threads->findById($threadId);

        if ($thread === null) {
            throw ForumException::notFound('Thread', $threadId);
        }

        if ($thread->isLocked) {
            throw ForumException::unauthorized('reply to locked thread');
        }

        $post = Post::create(
            id: UuidGenerator::v7(),
            threadId: $threadId,
            authorId: $authorId,
            body: $body,
            bodyHtml: $bodyHtml,
            ipHash: $ipHash,
            userAgentHash: $userAgentHash,
            tenantId: $tenantId,
            editWindowMinutes: $this->config->editWindowMinutes,
        );

        $this->posts->save($post);

        // C-1: Atomic reply count increment
        $this->threads->incrementReplyCount($threadId);

        // Ensure the author has a profile
        $this->reputationService->getOrCreateProfile($authorId, $tenantId);

        // C-2: Atomic post count increment
        $this->profiles->incrementPostCount($authorId, $tenantId);

        // Award reputation for creating a post
        $this->reputationService->addReputation(
            $authorId,
            $this->config->reputation->pointsPerPost,
            'created_post',
        );

        $this->events->dispatch(new PostCreated(
            postId: $post->id,
            threadId: $post->threadId,
            authorId: $post->authorId,
            tenantId: $post->tenantId,
            authorDisplayName: $authorDisplayName !== '' ? $authorDisplayName : $post->authorId,
        ));

        return $post;
    }

    public function editPost(string $postId, string $newBody, string $newBodyHtml, string $editedBy, bool $isModerator = false): Post
    {
        $post = $this->posts->findById($postId);

        if ($post === null) {
            throw ForumException::notFound('Post', $postId);
        }

        if ($editedBy !== $post->authorId && !$isModerator) {
            throw ForumException::unauthorized('edit post by non-author');
        }

        $post = $post->edit($newBody, $newBodyHtml, $editedBy);
        $this->posts->save($post);

        $this->events->dispatch(new PostEdited(
            postId: $post->id,
            threadId: $post->threadId,
            editedBy: $editedBy,
            tenantId: $post->tenantId,
        ));

        return $post;
    }

    public function deletePost(string $postId, string $deletedBy = ''): void
    {
        $post = $this->posts->findById($postId);

        if ($post === null) {
            throw ForumException::notFound('Post', $postId);
        }

        $this->posts->delete($post);

        // Decrement the thread's reply count atomically
        $this->threads->incrementReplyCount($post->threadId, -1);

        // Decrement the author's post count atomically
        $this->profiles->incrementPostCount($post->authorId, $post->tenantId, -1);

        $this->events->dispatch(new PostDeleted(
            postId: $post->id,
            threadId: $post->threadId,
            deletedBy: $deletedBy !== '' ? $deletedBy : $post->authorId,
            tenantId: $post->tenantId,
        ));
    }

    public function deleteThread(string $threadId, string $deletedBy = ''): void
    {
        $thread = $this->threads->findById($threadId);

        if ($thread === null) {
            throw ForumException::notFound('Thread', $threadId);
        }

        $this->threads->delete($thread);

        // Decrement the author's thread count atomically
        $this->profiles->incrementThreadCount($thread->authorId, $thread->tenantId, -1);

        $this->events->dispatch(new ThreadDeleted(
            threadId: $thread->id,
            deletedBy: $deletedBy !== '' ? $deletedBy : $thread->authorId,
            tenantId: $thread->tenantId,
        ));
    }

    public function acceptSolution(string $threadId, string $postId): Thread
    {
        $thread = $this->threads->findById($threadId);

        if ($thread === null) {
            throw ForumException::notFound('Thread', $threadId);
        }

        $post = $this->posts->findById($postId);

        if ($post === null) {
            throw ForumException::notFound('Post', $postId);
        }

        // Mark thread as solved and post as solution
        $thread = $thread->solve($postId);
        $this->threads->save($thread);

        $post = $post->markAsSolution();
        $this->posts->save($post);

        // Award reputation for having a solution accepted
        $this->reputationService->addReputation(
            $post->authorId,
            $this->config->reputation->pointsPerSolution,
            'solution_accepted',
        );

        $this->events->dispatch(new PostAcceptedAsSolution(
            postId: $post->id,
            threadId: $thread->id,
            postAuthorId: $post->authorId,
            acceptedBy: $thread->authorId,
            tenantId: $thread->tenantId,
        ));

        // Evaluate solution-related badges
        $this->badgeService->award($post->authorId, Badge::FirstAnswer, $post->tenantId);
        $this->badgeService->award($post->authorId, Badge::Solver, $post->tenantId);

        return $thread;
    }

    public function lockThread(string $threadId, string $actorId = ''): Thread
    {
        $thread = $this->threads->findById($threadId);

        if ($thread === null) {
            throw ForumException::notFound('Thread', $threadId);
        }

        $thread = $thread->lock();
        $this->threads->save($thread);

        $this->events->dispatch(new ThreadLocked(
            threadId: $thread->id,
            lockedBy: $actorId !== '' ? $actorId : $thread->authorId,
            tenantId: $thread->tenantId,
        ));

        return $thread;
    }

    public function unlockThread(string $threadId, string $actorId = ''): Thread
    {
        $thread = $this->threads->findById($threadId);

        if ($thread === null) {
            throw ForumException::notFound('Thread', $threadId);
        }

        $thread = $thread->unlock();
        $this->threads->save($thread);

        $this->events->dispatch(new ThreadUnlocked(
            threadId: $thread->id,
            unlockedBy: $actorId !== '' ? $actorId : $thread->authorId,
            tenantId: $thread->tenantId,
        ));

        return $thread;
    }

    public function pinThread(string $threadId, string $actorId = ''): Thread
    {
        $thread = $this->threads->findById($threadId);

        if ($thread === null) {
            throw ForumException::notFound('Thread', $threadId);
        }

        $thread = $thread->pin();
        $this->threads->save($thread);

        $this->events->dispatch(new ThreadPinned(
            threadId: $thread->id,
            pinnedBy: $actorId !== '' ? $actorId : $thread->authorId,
            tenantId: $thread->tenantId,
        ));

        return $thread;
    }

    public function unpinThread(string $threadId, string $actorId = ''): Thread
    {
        $thread = $this->threads->findById($threadId);

        if ($thread === null) {
            throw ForumException::notFound('Thread', $threadId);
        }

        $thread = $thread->unpin();
        $this->threads->save($thread);

        $this->events->dispatch(new ThreadUnpinned(
            threadId: $thread->id,
            unpinnedBy: $actorId !== '' ? $actorId : $thread->authorId,
            tenantId: $thread->tenantId,
        ));

        return $thread;
    }
}
