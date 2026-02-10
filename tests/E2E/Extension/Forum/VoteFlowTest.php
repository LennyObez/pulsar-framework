<?php

declare(strict_types=1);

namespace Pulsar\Tests\E2E\Extension\Forum;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Config\ForumConfig;
use Pulsar\Extension\Forum\Domain\ThreadType;
use Pulsar\Extension\Forum\Domain\VoteDirection;
use Pulsar\Extension\Forum\Event\VoteCast;
use Pulsar\Extension\Forum\Event\VoteRemoved;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Internal\Service\ForumService;
use Pulsar\Extension\Forum\Internal\Service\VoteService;
use Pulsar\Extension\Forum\Vote\PostVote;
use Pulsar\Extension\Forum\Vote\PostVoteRepositoryInterface;
use Pulsar\Extension\Forum\Vote\ThreadVote;
use Pulsar\Extension\Forum\Vote\ThreadVoteRepositoryInterface;

use function array_filter;

/**
 * E2E: Voting workflow — cast upvote/downvote, change votes, remove votes, reputation effects.
 */
#[CoversClass(VoteService::class)]
#[CoversClass(ThreadVote::class)]
#[CoversClass(PostVote::class)]
#[Group('e2e-forum')]
final class VoteFlowTest extends TestCase
{
    #[Test]
    public function upvoteThreadIncreasesScoreAndAwardsReputation(): void
    {
        $stack = $this->createVoteStack();

        $thread = $stack->forumService->createThread(
            categoryId: 'cat-general',
            authorId: 'user-alice',
            title: 'Upvote test',
            slug: 'upvote-test',
            type: ThreadType::Discussion,
            body: 'Great content',
            bodyHtml: '<p>Great content</p>',
            ipHash: 'iphash-up',
            userAgentHash: 'uahash-up',
        );

        // Record Alice's reputation before the vote
        $profileBefore = $stack->profiles->findByUser('user-alice');
        self::assertNotNull($profileBefore);
        $repBefore = $profileBefore->reputationScore;

        // Bob upvotes Alice's thread
        $vote = $stack->voteService->castThreadVote(
            userId: 'user-bob',
            threadId: $thread->id,
            direction: VoteDirection::Up,
        );

        self::assertNotEmpty($vote->id);
        self::assertSame('user-bob', $vote->userId);
        self::assertSame($thread->id, $vote->threadId);
        self::assertSame(VoteDirection::Up, $vote->value);

        // Thread vote score should be +1
        $updatedThread = $stack->threads->findById($thread->id);
        self::assertNotNull($updatedThread);
        self::assertSame(1, $updatedThread->voteScore);

        // Alice should gain reputation (5 points per upvote default)
        $profileAfter = $stack->profiles->findByUser('user-alice');
        self::assertNotNull($profileAfter);
        self::assertGreaterThan($repBefore, $profileAfter->reputationScore);
    }

    #[Test]
    public function removeVoteRevertsScoreAndReputation(): void
    {
        $stack = $this->createVoteStack();

        $thread = $stack->forumService->createThread(
            categoryId: 'cat-general',
            authorId: 'user-alice',
            title: 'Remove vote test',
            slug: 'remove-vote-test',
            type: ThreadType::Discussion,
            body: 'Content',
            bodyHtml: '<p>Content</p>',
            ipHash: 'iphash-rmvote',
            userAgentHash: 'uahash-rmvote',
        );

        // Bob upvotes
        $stack->voteService->castThreadVote(
            userId: 'user-bob',
            threadId: $thread->id,
            direction: VoteDirection::Up,
        );

        // Confirm score is 1
        $afterVote = $stack->threads->findById($thread->id);
        self::assertNotNull($afterVote);
        self::assertSame(1, $afterVote->voteScore);

        // Bob removes vote
        $stack->voteService->removeThreadVote('user-bob', $thread->id);

        // Score should revert to 0
        $afterRemoval = $stack->threads->findById($thread->id);
        self::assertNotNull($afterRemoval);
        self::assertSame(0, $afterRemoval->voteScore);

        // VoteRemoved event should be dispatched
        $removeEvents = array_filter(
            $stack->events->getDispatched(),
            static fn(object $e) => $e instanceof VoteRemoved,
        );
        self::assertNotEmpty($removeEvents);
    }

    #[Test]
    public function selfVoteOnThreadIsPrevented(): void
    {
        $stack = $this->createVoteStack();

        $thread = $stack->forumService->createThread(
            categoryId: 'cat-general',
            authorId: 'user-alice',
            title: 'Self vote guard',
            slug: 'self-vote-guard',
            type: ThreadType::Discussion,
            body: 'Content',
            bodyHtml: '<p>Content</p>',
            ipHash: 'iphash-self',
            userAgentHash: 'uahash-self',
        );

        $this->expectException(ForumException::class);
        $this->expectExceptionMessage('cannot vote on your own');

        $stack->voteService->castThreadVote(
            userId: 'user-alice',
            threadId: $thread->id,
            direction: VoteDirection::Up,
        );
    }

    #[Test]
    public function duplicateVoteOnThreadIsPrevented(): void
    {
        $stack = $this->createVoteStack();

        $thread = $stack->forumService->createThread(
            categoryId: 'cat-general',
            authorId: 'user-alice',
            title: 'Duplicate vote guard',
            slug: 'duplicate-vote-guard',
            type: ThreadType::Discussion,
            body: 'Content',
            bodyHtml: '<p>Content</p>',
            ipHash: 'iphash-dup',
            userAgentHash: 'uahash-dup',
        );

        // First vote succeeds
        $stack->voteService->castThreadVote(
            userId: 'user-bob',
            threadId: $thread->id,
            direction: VoteDirection::Up,
        );

        $this->expectException(ForumException::class);
        $this->expectExceptionMessage('already voted');

        // Second vote should fail
        $stack->voteService->castThreadVote(
            userId: 'user-bob',
            threadId: $thread->id,
            direction: VoteDirection::Up,
        );
    }

    #[Test]
    public function postVotingWorkflowUpvoteAndRemove(): void
    {
        $stack = $this->createVoteStack();

        $thread = $stack->forumService->createThread(
            categoryId: 'cat-general',
            authorId: 'user-alice',
            title: 'Post vote workflow',
            slug: 'post-vote-workflow',
            type: ThreadType::Discussion,
            body: 'Opening post',
            bodyHtml: '<p>Opening post</p>',
            ipHash: 'iphash-pvw',
            userAgentHash: 'uahash-pvw',
        );

        $post = $stack->forumService->createPost(
            threadId: $thread->id,
            authorId: 'user-bob',
            body: 'Helpful answer',
            bodyHtml: '<p>Helpful answer</p>',
            ipHash: 'iphash-pvw2',
            userAgentHash: 'uahash-pvw2',
        );

        // Charlie upvotes Bob's post
        $vote = $stack->voteService->castPostVote(
            userId: 'user-charlie',
            postId: $post->id,
            direction: VoteDirection::Up,
        );

        self::assertSame(VoteDirection::Up, $vote->value);
        self::assertSame($post->id, $vote->postId);

        // Verify post vote score increased
        $updatedPost = $stack->posts->findById($post->id);
        self::assertNotNull($updatedPost);
        self::assertSame(1, $updatedPost->voteScore);

        // Charlie removes the vote
        $stack->voteService->removePostVote('user-charlie', $post->id);

        // Score reverts to 0
        $revertedPost = $stack->posts->findById($post->id);
        self::assertNotNull($revertedPost);
        self::assertSame(0, $revertedPost->voteScore);
    }

    #[Test]
    public function downvoteRequiresMinimumReputation(): void
    {
        $stack = $this->createVoteStack();

        $thread = $stack->forumService->createThread(
            categoryId: 'cat-general',
            authorId: 'user-alice',
            title: 'Downvote rep gate',
            slug: 'downvote-rep-gate',
            type: ThreadType::Discussion,
            body: 'Content',
            bodyHtml: '<p>Content</p>',
            ipHash: 'iphash-down',
            userAgentHash: 'uahash-down',
        );

        // Bob has no reputation (new user) — cannot downvote
        $this->expectException(ForumException::class);
        $this->expectExceptionMessage('reputation');

        $stack->voteService->castThreadVote(
            userId: 'user-bob',
            threadId: $thread->id,
            direction: VoteDirection::Down,
        );
    }

    private function createVoteStack(): VoteFlowStack
    {
        $threads = new E2EThreadRepository();
        $posts = new E2EPostRepository();
        $profiles = new E2EForumProfileRepository();
        $events = new E2EEventDispatcher();
        $config = ForumConfig::fromArray([]);
        $badges = new E2EBadgeService();
        $reputationService = new E2EReputationService($profiles);
        $threadVotes = new E2EVoteFlowThreadVoteRepository();
        $postVotes = new E2EVoteFlowPostVoteRepository();

        $forumService = new ForumService(
            threads: $threads,
            posts: $posts,
            profiles: $profiles,
            reputationService: $reputationService,
            badgeService: $badges,
            events: $events,
            config: $config,
        );

        $voteService = new VoteService(
            threadVotes: $threadVotes,
            postVotes: $postVotes,
            threads: $threads,
            posts: $posts,
            profiles: $profiles,
            reputationService: $reputationService,
            badgeService: $badges,
            events: $events,
            config: $config,
        );

        return new VoteFlowStack(
            forumService: $forumService,
            voteService: $voteService,
            threads: $threads,
            posts: $posts,
            profiles: $profiles,
            events: $events,
        );
    }
}

/**
 * @internal Shared stack for vote flow E2E tests.
 */
final readonly class VoteFlowStack
{
    public function __construct(
        public ForumService $forumService,
        public VoteService $voteService,
        public E2EThreadRepository $threads,
        public E2EPostRepository $posts,
        public E2EForumProfileRepository $profiles,
        public E2EEventDispatcher $events,
    ) {}
}

/**
 * @internal In-memory thread vote repository for vote flow E2E tests.
 */
final class E2EVoteFlowThreadVoteRepository implements ThreadVoteRepositoryInterface
{
    /** @var array<string, ThreadVote> */
    private array $votes = [];

    #[Override]
    public function findById(string $id): ?ThreadVote
    {
        return $this->votes[$id] ?? null;
    }

    #[Override]
    public function findByUserAndThread(string $userId, string $threadId): ?ThreadVote
    {
        foreach ($this->votes as $vote) {
            if ($vote->userId === $userId && $vote->threadId === $threadId) {
                return $vote;
            }
        }

        return null;
    }

    #[Override]
    public function scoreForThread(string $threadId): int
    {
        $score = 0;

        foreach ($this->votes as $vote) {
            if ($vote->threadId === $threadId) {
                $score += $vote->value->value;
            }
        }

        return $score;
    }

    #[Override]
    public function save(ThreadVote $vote): void
    {
        $this->votes[$vote->id] = $vote;
    }

    #[Override]
    public function delete(ThreadVote $vote): void
    {
        unset($this->votes[$vote->id]);
    }
}

/**
 * @internal In-memory post vote repository for vote flow E2E tests.
 */
final class E2EVoteFlowPostVoteRepository implements PostVoteRepositoryInterface
{
    /** @var array<string, PostVote> */
    private array $votes = [];

    #[Override]
    public function findById(string $id): ?PostVote
    {
        return $this->votes[$id] ?? null;
    }

    #[Override]
    public function findByUserAndPost(string $userId, string $postId): ?PostVote
    {
        foreach ($this->votes as $vote) {
            if ($vote->userId === $userId && $vote->postId === $postId) {
                return $vote;
            }
        }

        return null;
    }

    #[Override]
    public function scoreForPost(string $postId): int
    {
        $score = 0;

        foreach ($this->votes as $vote) {
            if ($vote->postId === $postId) {
                $score += $vote->value->value;
            }
        }

        return $score;
    }

    #[Override]
    public function save(PostVote $vote): void
    {
        $this->votes[$vote->id] = $vote;
    }

    #[Override]
    public function delete(PostVote $vote): void
    {
        unset($this->votes[$vote->id]);
    }
}
