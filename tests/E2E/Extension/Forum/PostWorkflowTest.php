<?php

declare(strict_types=1);

namespace Pulsar\Tests\E2E\Extension\Forum;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Config\ForumConfig;
use Pulsar\Extension\Forum\Domain\Badge;
use Pulsar\Extension\Forum\Domain\ThreadType;
use Pulsar\Extension\Forum\Event\PostCreated;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Internal\Service\ForumService;
use Pulsar\Extension\Forum\Post\Post;

use function array_filter;

/**
 * E2E: Post workflow — create reply -> edit within window -> accept as solution -> delete.
 */
#[CoversClass(ForumService::class)]
#[CoversClass(Post::class)]
#[Group('e2e-forum')]
final class PostWorkflowTest extends TestCase
{
    #[Test]
    public function fullPostLifecycleCreateEditSolutionDelete(): void
    {
        $stack = $this->createStack();

        // Step 1: Create thread (Q&A type supports solutions)
        $thread = $stack->forumService->createThread(
            categoryId: 'cat-help',
            authorId: 'user-alice',
            title: 'How do I configure routing?',
            slug: 'how-do-i-configure-routing',
            type: ThreadType::Question,
            body: 'I need help with route definitions.',
            bodyHtml: '<p>I need help with route definitions.</p>',
            ipHash: 'iphash-lifecycle',
            userAgentHash: 'uahash-lifecycle',
        );

        // Step 2: Create a reply
        $post = $stack->forumService->createPost(
            threadId: $thread->id,
            authorId: 'user-bob',
            body: 'Use the Router class with attribute-based routes.',
            bodyHtml: '<p>Use the Router class with attribute-based routes.</p>',
            ipHash: 'iphash-reply',
            userAgentHash: 'uahash-reply',
        );

        self::assertNotEmpty($post->id);
        self::assertSame($thread->id, $post->threadId);
        self::assertSame('user-bob', $post->authorId);
        self::assertFalse($post->isSolution);
        self::assertSame(0, $post->editCount);

        // Step 3: Edit the reply within the edit window
        $edited = $stack->forumService->editPost(
            postId: $post->id,
            newBody: 'Use the Router class with attribute-based routes. See docs for examples.',
            newBodyHtml: '<p>Use the Router class with attribute-based routes. See docs for examples.</p>',
            editedBy: 'user-bob',
        );

        self::assertSame(1, $edited->editCount);
        self::assertSame('user-bob', $edited->editedBy);
        self::assertNotNull($edited->editedAt);
        self::assertStringContainsString('See docs for examples', $edited->body);

        // Step 4: Accept the post as the solution
        $solvedThread = $stack->forumService->acceptSolution($thread->id, $post->id);

        self::assertSame($post->id, $solvedThread->solvedPostId);
        self::assertTrue($solvedThread->isSolved());

        // Verify the post is flagged as solution
        $solutionPost = $stack->posts->findById($post->id);
        self::assertNotNull($solutionPost);
        self::assertTrue($solutionPost->isSolution);

        // Step 5: Delete the post (author deletes own post; not a moderator action)
        $stack->forumService->deletePost($post->id, 'user-bob');
        $deleted = $stack->posts->findById($post->id);
        self::assertNull($deleted, 'Deleted post should not be retrievable');
    }

    #[Test]
    public function postOnLockedThreadThrowsException(): void
    {
        $stack = $this->createStack();

        $thread = $stack->forumService->createThread(
            categoryId: 'cat-general',
            authorId: 'user-mod',
            title: 'Locked thread',
            slug: 'locked-thread',
            type: ThreadType::Discussion,
            body: 'Will be locked',
            bodyHtml: '<p>Will be locked</p>',
            ipHash: 'iphash-locked',
            userAgentHash: 'uahash-locked',
        );

        $stack->forumService->lockThread($thread->id);

        $this->expectException(ForumException::class);
        $this->expectExceptionMessageIsOrContains('locked');

        $stack->forumService->createPost(
            threadId: $thread->id,
            authorId: 'user-bob',
            body: 'Should fail',
            bodyHtml: '<p>Should fail</p>',
            ipHash: 'iphash-locked2',
            userAgentHash: 'uahash-locked2',
        );
    }

    #[Test]
    public function editByNonAuthorWithoutModeratorFlagThrows(): void
    {
        $stack = $this->createStack();

        $thread = $stack->forumService->createThread(
            categoryId: 'cat-general',
            authorId: 'user-alice',
            title: 'Auth edit guard',
            slug: 'auth-edit-guard',
            type: ThreadType::Discussion,
            body: 'Opening',
            bodyHtml: '<p>Opening</p>',
            ipHash: 'iphash-auth',
            userAgentHash: 'uahash-auth',
        );

        $post = $stack->forumService->createPost(
            threadId: $thread->id,
            authorId: 'user-bob',
            body: 'Bobs reply',
            bodyHtml: '<p>Bobs reply</p>',
            ipHash: 'iphash-auth2',
            userAgentHash: 'uahash-auth2',
        );

        $this->expectException(ForumException::class);
        $this->expectExceptionMessageIsOrContains('non-author');

        $stack->forumService->editPost(
            postId: $post->id,
            newBody: 'Hijacked!',
            newBodyHtml: '<p>Hijacked!</p>',
            editedBy: 'user-charlie',
        );
    }

    #[Test]
    public function moderatorCanEditAnyPost(): void
    {
        $stack = $this->createStack();

        $thread = $stack->forumService->createThread(
            categoryId: 'cat-general',
            authorId: 'user-alice',
            title: 'Mod edit test',
            slug: 'mod-edit-test',
            type: ThreadType::Discussion,
            body: 'Opening',
            bodyHtml: '<p>Opening</p>',
            ipHash: 'iphash-modedit',
            userAgentHash: 'uahash-modedit',
        );

        $post = $stack->forumService->createPost(
            threadId: $thread->id,
            authorId: 'user-bob',
            body: 'Inappropriate content',
            bodyHtml: '<p>Inappropriate content</p>',
            ipHash: 'iphash-modedit2',
            userAgentHash: 'uahash-modedit2',
        );

        $edited = $stack->forumService->editPost(
            postId: $post->id,
            newBody: '[Content removed by moderator]',
            newBodyHtml: '<p>[Content removed by moderator]</p>',
            editedBy: 'user-moderator',
            isModerator: true,
        );

        self::assertSame('[Content removed by moderator]', $edited->body);
        self::assertSame('user-moderator', $edited->editedBy);
    }

    #[Test]
    public function solutionAcceptanceAwardsBadgesAndReputation(): void
    {
        $stack = $this->createStack();

        $thread = $stack->forumService->createThread(
            categoryId: 'cat-help',
            authorId: 'user-alice',
            title: 'Question for badge test',
            slug: 'question-for-badge-test',
            type: ThreadType::Question,
            body: 'Need an answer',
            bodyHtml: '<p>Need an answer</p>',
            ipHash: 'iphash-badge',
            userAgentHash: 'uahash-badge',
        );

        $answer = $stack->forumService->createPost(
            threadId: $thread->id,
            authorId: 'user-bob',
            body: 'Here is the answer',
            bodyHtml: '<p>Here is the answer</p>',
            ipHash: 'iphash-badge2',
            userAgentHash: 'uahash-badge2',
        );

        // Record reputation before solution acceptance
        $profileBefore = $stack->profiles->findByUser('user-bob');
        self::assertNotNull($profileBefore);
        $repBefore = $profileBefore->reputationScore;

        $stack->forumService->acceptSolution($thread->id, $answer->id);

        // Verify reputation increased (solution accepted = 15 points default)
        $profileAfter = $stack->profiles->findByUser('user-bob');
        self::assertNotNull($profileAfter);
        self::assertGreaterThan($repBefore, $profileAfter->reputationScore);

        // Verify badges awarded
        self::assertTrue($stack->badges->hasBadge('user-bob', Badge::FirstAnswer));
        self::assertTrue($stack->badges->hasBadge('user-bob', Badge::Solver));
    }

    #[Test]
    public function multipleRepliesIncrementReplyCountAccurately(): void
    {
        $stack = $this->createStack();

        $thread = $stack->forumService->createThread(
            categoryId: 'cat-general',
            authorId: 'user-alice',
            title: 'Multi reply counter',
            slug: 'multi-reply-counter',
            type: ThreadType::Discussion,
            body: 'Discuss!',
            bodyHtml: '<p>Discuss!</p>',
            ipHash: 'iphash-multi',
            userAgentHash: 'uahash-multi',
        );

        for ($i = 1; $i <= 5; $i++) {
            $stack->forumService->createPost(
                threadId: $thread->id,
                authorId: "user-{$i}",
                body: "Reply {$i}",
                bodyHtml: "<p>Reply {$i}</p>",
                ipHash: "iphash-multi-{$i}",
                userAgentHash: "uahash-multi-{$i}",
            );
        }

        $updatedThread = $stack->threads->findById($thread->id);
        self::assertNotNull($updatedThread);
        self::assertSame(5, $updatedThread->replyCount);

        // Verify domain events were dispatched for each post
        $postCreatedEvents = array_filter(
            $stack->events->getDispatched(),
            static fn(object $e) => $e instanceof PostCreated,
        );
        self::assertCount(5, $postCreatedEvents);
    }

    private function createStack(): PostWorkflowStack
    {
        $threads = new E2EThreadRepository();
        $posts = new E2EPostRepository();
        $profiles = new E2EForumProfileRepository();
        $events = new E2EEventDispatcher();
        $config = ForumConfig::fromArray([]);
        $badges = new E2EBadgeService();
        $reputationService = new E2EReputationService($profiles);

        $forumService = new ForumService(
            threads: $threads,
            posts: $posts,
            profiles: $profiles,
            reputationService: $reputationService,
            badgeService: $badges,
            events: $events,
            config: $config,
        );

        return new PostWorkflowStack(
            forumService: $forumService,
            threads: $threads,
            posts: $posts,
            profiles: $profiles,
            badges: $badges,
            events: $events,
        );
    }
}

/**
 * @internal Shared stack for post workflow E2E tests.
 */
final readonly class PostWorkflowStack
{
    public function __construct(
        public ForumService $forumService,
        public E2EThreadRepository $threads,
        public E2EPostRepository $posts,
        public E2EForumProfileRepository $profiles,
        public E2EBadgeService $badges,
        public E2EEventDispatcher $events,
    ) {}
}
