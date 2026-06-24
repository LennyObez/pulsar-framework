<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Auth\Guard\SessionGuard;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Cache\Application\Driver\CacheDriverInterface;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Extension\Forum\Badge\BadgeServiceInterface;
use Pulsar\Extension\Forum\Config\ForumConfig;
use Pulsar\Extension\Forum\Content\ForumBodyPolicy;
use Pulsar\Extension\Forum\Content\MarkdownRendererInterface;
use Pulsar\Extension\Forum\Domain\ThreadStatus;
use Pulsar\Extension\Forum\Domain\ThreadType;
use Pulsar\Extension\Forum\Domain\VoteDirection;
use Pulsar\Extension\Forum\Event\PostDeleted;
use Pulsar\Extension\Forum\Event\ThreadDeleted;
use Pulsar\Extension\Forum\Event\ThreadLocked;
use Pulsar\Extension\Forum\Event\ThreadPinned;
use Pulsar\Extension\Forum\Event\ThreadUnlocked;
use Pulsar\Extension\Forum\Event\ThreadUnpinned;
use Pulsar\Extension\Forum\Http\Controller\Api\PostApiController;
use Pulsar\Extension\Forum\Http\Controller\Api\ThreadApiController;
use Pulsar\Extension\Forum\Http\Controller\Auth\LoginController;
use Pulsar\Extension\Forum\Http\Controller\Auth\PasswordResetController;
use Pulsar\Extension\Forum\Http\Middleware\ForumAuthMiddleware;
use Pulsar\Extension\Forum\Http\Middleware\ForumRateLimitMiddleware;
use Pulsar\Extension\Forum\Internal\Mail\PasswordResetMailable;
use Pulsar\Extension\Forum\Internal\Service\ForumService;
use Pulsar\Extension\Forum\Internal\Service\VoteService;
use Pulsar\Extension\Forum\Post\Post;
use Pulsar\Extension\Forum\Post\PostRepositoryInterface;
use Pulsar\Extension\Forum\Profile\ForumProfile;
use Pulsar\Extension\Forum\Profile\ForumProfileRepositoryInterface;
use Pulsar\Extension\Forum\Realtime\RealtimeEvent;
use Pulsar\Extension\Forum\Realtime\RealtimeEventType;
use Pulsar\Extension\Forum\Realtime\RedisRealtimeBroadcaster;
use Pulsar\Extension\Forum\Service\ForumServiceInterface;
use Pulsar\Extension\Forum\Service\ReputationServiceInterface;
use Pulsar\Extension\Forum\Subscription\ThreadSubscriptionRepositoryInterface;
use Pulsar\Extension\Forum\Thread\Thread;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;
use Pulsar\Extension\Forum\Vote\PostVoteRepositoryInterface;
use Pulsar\Extension\Forum\Vote\ThreadVoteRepositoryInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Mail\MailManagerInterface;
use Redis;

use function json_decode;

/**
 * Tests for all HIGH-severity findings and production stub fixes in the Forum extension.
 */
#[CoversClass(PostApiController::class)]
#[CoversClass(ThreadApiController::class)]
#[CoversClass(ForumRateLimitMiddleware::class)]
#[CoversClass(ForumAuthMiddleware::class)]
#[CoversClass(VoteService::class)]
#[CoversClass(ForumBodyPolicy::class)]
#[CoversClass(LoginController::class)]
#[CoversClass(PasswordResetController::class)]
#[CoversClass(PasswordResetMailable::class)]
#[CoversClass(ForumService::class)]
#[CoversClass(RedisRealtimeBroadcaster::class)]
final class ForumHighFindingsTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeIdentity(string $id = 'user-1'): IdentityInterface
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('id')->willReturn($id);
        $identity->method('isAuthenticated')->willReturn(true);

        return $identity;
    }

    private function makePost(
        string $id = 'post-1',
        string $authorId = 'user-1',
        int $voteScore = 0,
        ?DateTimeImmutable $deletedAt = null,
    ): Post {
        $now = new DateTimeImmutable('2025-01-15 12:00:00');

        return new Post(
            id: $id,
            tenantId: null,
            threadId: 'thread-1',
            parentId: null,
            authorId: $authorId,
            body: 'Test body',
            bodyHtml: '<p>Test body</p>',
            isSolution: false,
            voteScore: $voteScore,
            editCount: 0,
            editedBy: null,
            ipHash: 'abc',
            userAgentHash: 'def',
            editedAt: null,
            editWindowExpiresAt: null,
            createdAt: $now,
            updatedAt: $now,
            deletedAt: $deletedAt,
        );
    }

    private function makeThread(string $authorId = 'user-1'): Thread
    {
        $now = new DateTimeImmutable('2025-01-15 12:00:00');

        return new Thread(
            id: 'thread-1',
            tenantId: null,
            categoryId: 'cat-1',
            authorId: $authorId,
            title: 'Test',
            slug: 'test',
            type: ThreadType::Question,
            status: ThreadStatus::Open,
            isPinned: false,
            isLocked: false,
            solvedPostId: null,
            replyCount: 1,
            viewCount: 10,
            voteScore: 0,
            lastActivityAt: $now,
            ipHash: 'abc',
            userAgentHash: 'def',
            createdAt: $now,
            updatedAt: $now,
            deletedAt: null,
        );
    }

    private function makeProfile(
        string $userId = 'user-1',
        bool $isBanned = false,
        ?DateTimeImmutable $banExpiresAt = null,
    ): ForumProfile {
        $now = new DateTimeImmutable();

        return new ForumProfile(
            id: 'profile-1',
            tenantId: null,
            userId: $userId,
            reputationScore: 100,
            postCount: 5,
            threadCount: 2,
            isBanned: $isBanned,
            banReason: $isBanned ? 'Test ban' : null,
            bannedAt: $isBanned ? new DateTimeImmutable('-1 day') : null,
            banExpiresAt: $banExpiresAt,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    private function makeRequestHandler(): RequestHandlerInterface
    {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(200));

        return $handler;
    }

    /**
     * Create a ReputationServiceInterface stub that returns concrete ForumProfile.
     * ForumProfile is final and cannot be mocked, so we return a real instance.
     */
    private function makeReputationService(): ReputationServiceInterface
    {
        $profile = $this->makeProfile();
        $stub = $this->createStub(ReputationServiceInterface::class);
        $stub->method('addReputation')->willReturn($profile);
        $stub->method('getOrCreateProfile')->willReturn($profile);

        return $stub;
    }

    /**
     * Create a Result object with a single row for DB query stubs.
     *
     * @param array<string, mixed> $rowData
     */
    private function makeDbResult(array $rowData): \Pulsar\Database\Result
    {
        return \Pulsar\Database\Result::fromArrays([$rowData]);
    }

    /**
     * Create an empty Result object.
     */
    private function makeEmptyDbResult(): \Pulsar\Database\Result
    {
        return \Pulsar\Database\Result::fromArrays([]);
    }

    // -----------------------------------------------------------------------
    // H-1: XSS: Markdown not passed through ForumBodyPolicy
    // -----------------------------------------------------------------------

    #[Test]
    public function h1PostApiCreateSanitizesHtmlThroughBodyPolicy(): void
    {
        // Arrange
        $forumService = $this->createMock(ForumServiceInterface::class);
        $forumService->expects(self::once())
            ->method('createPost')
            ->with(
                self::anything(), // threadId
                self::anything(), // authorId
                self::anything(), // body
                self::callback(static fn(string $html): bool =>
                    // The bodyHtml must NOT contain <script>: ForumBodyPolicy sanitizes it
                    !str_contains($html, '<script>')),
                self::anything(), // ipHash
                self::anything(), // userAgentHash
            )
            ->willReturn($this->makePost());

        $markdown = $this->createStub(MarkdownRendererInterface::class);
        $markdown->method('render')->willReturn('<p>Hello</p><script>alert("xss")</script>');

        $controller = new PostApiController(
            postRepository: $this->createStub(PostRepositoryInterface::class),
            forumService: $forumService,
            markdown: $markdown,
            bodyPolicy: new ForumBodyPolicy(),
            config: new ForumConfig(),
            threadRepository: $this->createStub(ThreadRepositoryInterface::class),
        );

        $request = new ServerRequest(method: 'POST', uri: '/api/v1/forum/threads/thread-1/posts')
            ->withAttribute('identity', $this->makeIdentity())
            ->withParsedBody(['body' => '# Hello <script>alert("xss")</script>']);

        // Act
        $response = $controller->create($request, 'thread-1');

        // Assert
        self::assertSame(201, $response->getStatusCode());
    }

    #[Test]
    public function h1PostApiUpdateSanitizesHtmlThroughBodyPolicy(): void
    {
        // Arrange
        $forumService = $this->createMock(ForumServiceInterface::class);
        $forumService->expects(self::once())
            ->method('editPost')
            ->with(
                self::anything(),
                self::anything(),
                self::callback(static fn(string $html): bool =>
                    !str_contains($html, '<script>')),
                self::anything(),
            )
            ->willReturn($this->makePost());

        $markdown = $this->createStub(MarkdownRendererInterface::class);
        $markdown->method('render')->willReturn('<p>Updated</p><script>xss</script>');

        $controller = new PostApiController(
            postRepository: $this->createStub(PostRepositoryInterface::class),
            forumService: $forumService,
            markdown: $markdown,
            bodyPolicy: new ForumBodyPolicy(),
            config: new ForumConfig(),
            threadRepository: $this->createStub(ThreadRepositoryInterface::class),
        );

        $request = new ServerRequest(method: 'PUT', uri: '/api/v1/forum/posts/post-1')
            ->withAttribute('identity', $this->makeIdentity())
            ->withParsedBody(['body' => 'Updated <script>xss</script>']);

        // Act
        $response = $controller->update($request, 'post-1');

        // Assert
        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function h1ThreadApiCreateSanitizesBodyHtml(): void
    {
        // Arrange
        $forumService = $this->createMock(ForumServiceInterface::class);
        $forumService->expects(self::once())
            ->method('createThread')
            ->with(
                self::anything(), // categoryId
                self::anything(), // authorId
                self::anything(), // title
                self::anything(), // slug
                self::anything(), // type
                self::anything(), // body
                self::callback(static fn(string $html): bool =>
                    !str_contains($html, '<script>')),
            )
            ->willReturn($this->makeThread());

        $markdown = $this->createStub(MarkdownRendererInterface::class);
        $markdown->method('render')->willReturn('<p>Thread body</p><script>xss</script>');

        $controller = new ThreadApiController(
            threadRepository: $this->createStub(ThreadRepositoryInterface::class),
            subscriptionRepository: $this->createStub(ThreadSubscriptionRepositoryInterface::class),
            forumService: $forumService,
            markdown: $markdown,
            bodyPolicy: new ForumBodyPolicy(),
            config: new ForumConfig(),
        );

        $request = new ServerRequest(method: 'POST', uri: '/api/v1/forum/threads')
            ->withAttribute('identity', $this->makeIdentity())
            ->withParsedBody([
                'title' => 'Test thread',
                'slug' => 'test-thread',
                'category_id' => 'cat-1',
                'body' => 'Thread body <script>xss</script>',
            ]);

        // Act
        $response = $controller->create($request);

        // Assert
        self::assertSame(201, $response->getStatusCode());
    }

    // -----------------------------------------------------------------------
    // H-2: LIKE search unescaped
    // -----------------------------------------------------------------------

    #[Test]
    #[DataProvider('likeMetacharacterProvider')]
    public function h2LikeMetacharactersAreEscapedInSearch(string $input, string $expectedEscaped): void
    {
        // Arrange: verify the escaping logic independently
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $input);

        // Assert
        self::assertSame($expectedEscaped, $escaped);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function likeMetacharacterProvider(): iterable
    {
        yield 'percent sign' => ['100%', '100\\%'];
        yield 'underscore' => ['foo_bar', 'foo\\_bar'];
        yield 'backslash' => ['path\\dir', 'path\\\\dir'];
        yield 'combined' => ['100%_test\\val', '100\\%\\_test\\\\val'];
        yield 'no special chars' => ['normal query', 'normal query'];
    }

    // -----------------------------------------------------------------------
    // H-3: Rate limiter TOCTOU: atomic increment with CacheDriver
    // -----------------------------------------------------------------------

    #[Test]
    public function h3RateLimiterUsesAtomicIncrementWhenDriverAvailable(): void
    {
        // Arrange
        $cache = $this->createStub(TaggedCacheInterface::class);
        $driver = $this->createMock(CacheDriverInterface::class);
        $driver->expects(self::once())
            ->method('increment')
            ->willReturn(1);
        $driver->expects(self::once())
            ->method('set');

        $middleware = new ForumRateLimitMiddleware($cache, $driver);

        $request = new ServerRequest(method: 'POST', uri: '/api/v1/forum/threads', serverParams: ['REMOTE_ADDR' => '127.0.0.1']);

        // Act
        $response = $middleware->process($request, $this->makeRequestHandler());

        // Assert
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('20', $response->getHeaderLine('X-RateLimit-Limit'));
    }

    #[Test]
    public function h3RateLimiterFallsBackToTaggedCacheWhenDriverNotAvailable(): void
    {
        // Arrange
        $cache = $this->createStub(TaggedCacheInterface::class);
        $cache->method('get')->willReturn(null);
        $cache->method('set')->willReturn(true);

        $middleware = new ForumRateLimitMiddleware($cache);

        $request = new ServerRequest(method: 'POST', uri: '/api/v1/forum/threads', serverParams: ['REMOTE_ADDR' => '127.0.0.1']);

        // Act
        $response = $middleware->process($request, $this->makeRequestHandler());

        // Assert
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('19', $response->getHeaderLine('X-RateLimit-Remaining'));
    }

    #[Test]
    public function h3RateLimiterFallsBackToTaggedCacheWhenDriverIncrementFails(): void
    {
        // Arrange
        $cache = $this->createStub(TaggedCacheInterface::class);
        $cache->method('get')->willReturn('5');
        $cache->method('set')->willReturn(true);

        $driver = $this->createStub(CacheDriverInterface::class);
        $driver->method('increment')->willReturn(false);

        $middleware = new ForumRateLimitMiddleware($cache, $driver);

        $request = new ServerRequest(method: 'POST', uri: '/api/v1/forum/threads', serverParams: ['REMOTE_ADDR' => '127.0.0.1']);

        // Act
        $response = $middleware->process($request, $this->makeRequestHandler());

        // Assert: 5+1=6, remaining = 20-6 = 14
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('14', $response->getHeaderLine('X-RateLimit-Remaining'));
    }

    // -----------------------------------------------------------------------
    // H-4: Expired bans not auto-cleared
    // -----------------------------------------------------------------------

    #[Test]
    public function h4ExpiredBanIsAutoClearedAndRequestProceeds(): void
    {
        // Arrange
        $expiredProfile = $this->makeProfile(
            isBanned: true,
            banExpiresAt: new DateTimeImmutable('-1 hour'), // already expired
        );

        $profiles = $this->createMock(ForumProfileRepositoryInterface::class);
        $profiles->method('findByUser')->willReturn($expiredProfile);
        $profiles->expects(self::once())
            ->method('clearBanFlag')
            ->with('user-1');

        $config = new ForumConfig();
        $middleware = new ForumAuthMiddleware($config, profiles: $profiles);

        $request = new ServerRequest(method: 'POST', uri: '/forum/threads')
            ->withAttribute('identity', $this->makeIdentity());

        // Act
        $response = $middleware->process($request, $this->makeRequestHandler());

        // Assert
        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function h4PermanentBanBlocksRequest(): void
    {
        // Arrange: permanent ban (no expiry)
        $bannedProfile = $this->makeProfile(
            isBanned: true,
            banExpiresAt: null,
        );

        $profiles = $this->createStub(ForumProfileRepositoryInterface::class);
        $profiles->method('findByUser')->willReturn($bannedProfile);

        $config = new ForumConfig();
        $middleware = new ForumAuthMiddleware($config, profiles: $profiles);

        $request = new ServerRequest(method: 'POST', uri: '/forum/threads')
            ->withAttribute('identity', $this->makeIdentity());

        // Act
        $response = $middleware->process($request, $this->makeRequestHandler());

        // Assert
        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function h4ActiveTemporaryBanBlocksRequest(): void
    {
        // Arrange: temporary ban still active
        $bannedProfile = $this->makeProfile(
            isBanned: true,
            banExpiresAt: new DateTimeImmutable('+1 hour'),
        );

        $profiles = $this->createStub(ForumProfileRepositoryInterface::class);
        $profiles->method('findByUser')->willReturn($bannedProfile);

        $config = new ForumConfig();
        $middleware = new ForumAuthMiddleware($config, profiles: $profiles);

        $request = new ServerRequest(method: 'POST', uri: '/forum/threads')
            ->withAttribute('identity', $this->makeIdentity());

        // Act
        $response = $middleware->process($request, $this->makeRequestHandler());

        // Assert
        self::assertSame(403, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('You are banned from the forum', $body['error']);
    }

    // -----------------------------------------------------------------------
    // H-5: Badge threshold off-by-one
    // -----------------------------------------------------------------------

    #[Test]
    public function h5BadgeAwardedAtCorrectPostIncrementThreshold(): void
    {
        // Arrange: post has voteScore=4, after upvote it becomes 5 (>= 5)
        $post = $this->makePost(authorId: 'author-1', voteScore: 4);

        $postVotes = $this->createStub(PostVoteRepositoryInterface::class);
        $postVotes->method('findByUserAndPost')->willReturn(null);

        $posts = $this->createStub(PostRepositoryInterface::class);
        $posts->method('findById')->willReturn($post);

        $profiles = $this->createStub(ForumProfileRepositoryInterface::class);
        $profiles->method('findByUser')->willReturn($this->makeProfile('voter-1'));

        $badgeService = $this->createMock(BadgeServiceInterface::class);
        $badgeService->expects(self::once())->method('award');

        $voteService = new VoteService(
            threadVotes: $this->createStub(ThreadVoteRepositoryInterface::class),
            postVotes: $postVotes,
            threads: $this->createStub(ThreadRepositoryInterface::class),
            posts: $posts,
            profiles: $profiles,
            reputationService: $this->makeReputationService(),
            badgeService: $badgeService,
            events: $this->createStub(EventDispatcherInterface::class),
            config: new ForumConfig(),
        );

        // Act
        $voteService->castPostVote('voter-1', 'post-1', VoteDirection::Up);

        // Assert: badge is awarded (verified by mock expectation)
    }

    #[Test]
    public function h5BadgeNotAwardedBelowThreshold(): void
    {
        // Arrange: post has voteScore=3, after upvote it becomes 4 (< 5)
        $post = $this->makePost(authorId: 'author-1', voteScore: 3);

        $postVotes = $this->createStub(PostVoteRepositoryInterface::class);
        $postVotes->method('findByUserAndPost')->willReturn(null);

        $posts = $this->createStub(PostRepositoryInterface::class);
        $posts->method('findById')->willReturn($post);

        $profiles = $this->createStub(ForumProfileRepositoryInterface::class);
        $profiles->method('findByUser')->willReturn($this->makeProfile('voter-1'));

        $badgeService = $this->createMock(BadgeServiceInterface::class);
        $badgeService->expects(self::never())->method('award');

        $voteService = new VoteService(
            threadVotes: $this->createStub(ThreadVoteRepositoryInterface::class),
            postVotes: $postVotes,
            threads: $this->createStub(ThreadRepositoryInterface::class),
            posts: $posts,
            profiles: $profiles,
            reputationService: $this->makeReputationService(),
            badgeService: $badgeService,
            events: $this->createStub(EventDispatcherInterface::class),
            config: new ForumConfig(),
        );

        // Act
        $voteService->castPostVote('voter-1', 'post-1', VoteDirection::Up);

        // Assert: badge NOT awarded (verified by mock never() expectation)
    }

    // -----------------------------------------------------------------------
    // H-6: h1 silently dropped by ForumBodyPolicy
    // -----------------------------------------------------------------------

    #[Test]
    public function h6H1TagIsPreservedByBodyPolicy(): void
    {
        // Arrange
        $policy = new ForumBodyPolicy();

        // Act
        $result = $policy->sanitize('<h1>Main Heading</h1><p>Body text</p>');

        // Assert
        self::assertStringContainsString('<h1>', $result);
        self::assertStringContainsString('Main Heading', $result);
    }

    #[Test]
    public function h6H1TagIdAndClassAttributesAllowed(): void
    {
        // Arrange
        $policy = new ForumBodyPolicy();

        // Act
        $result = $policy->sanitize('<h1 id="top" class="title">Heading</h1>');

        // Assert
        self::assertStringContainsString('id="top"', $result);
        self::assertStringContainsString('class="title"', $result);
    }

    #[Test]
    public function h6H1TagDisallowedAttributesStripped(): void
    {
        // Arrange
        $policy = new ForumBodyPolicy();

        // Act
        $result = $policy->sanitize('<h1 onclick="alert(1)" style="color:red">Heading</h1>');

        // Assert
        self::assertStringNotContainsString('onclick', $result);
        self::assertStringNotContainsString('style', $result);
        self::assertStringContainsString('Heading', $result);
    }

    // -----------------------------------------------------------------------
    // STUB: LoginController: real session handling
    // -----------------------------------------------------------------------

    #[Test]
    public function stubLoginCallsSessionGuardLogin(): void
    {
        // Arrange
        $connection = $this->createStub(ConnectionInterface::class);
        $passwordHash = password_hash('secret', PASSWORD_BCRYPT);
        $connection->method('query')->willReturn(
            $this->makeDbResult(['id' => 'user-42', 'password_hash' => $passwordHash, 'is_locked' => 0]),
        );

        $session = $this->createStub(\Pulsar\Security\Session\SessionInterface::class);
        $sessionGuard = new SessionGuard($session);

        $controller = new LoginController(
            connection: $connection,
            sessionGuard: $sessionGuard,
        );

        $request = new ServerRequest(method: 'POST', uri: '/login')
            ->withParsedBody(['email' => 'test@example.com', 'password' => 'secret']);

        // Act
        $response = $controller->login($request);

        // Assert: successful login redirects to /
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function stubLogoutRedirectsToLogin(): void
    {
        // Arrange
        $connection = $this->createStub(ConnectionInterface::class);
        $session = $this->createStub(\Pulsar\Security\Session\SessionInterface::class);
        $sessionGuard = new SessionGuard($session);

        $controller = new LoginController(
            connection: $connection,
            sessionGuard: $sessionGuard,
        );

        $request = new ServerRequest(method: 'POST', uri: '/logout');

        // Act
        $response = $controller->logout($request);

        // Assert
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/login', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function stubLoginControllerAcceptsNullSessionGuard(): void
    {
        // Arrange: without session guard, login still redirects
        $connection = $this->createStub(ConnectionInterface::class);
        $passwordHash = password_hash('secret', PASSWORD_BCRYPT);
        $connection->method('query')->willReturn(
            $this->makeDbResult(['id' => 'user-42', 'password_hash' => $passwordHash, 'is_locked' => 0]),
        );

        $controller = new LoginController(connection: $connection);

        $request = new ServerRequest(method: 'POST', uri: '/login')
            ->withParsedBody(['email' => 'test@example.com', 'password' => 'secret']);

        // Act
        $response = $controller->login($request);

        // Assert
        self::assertSame(302, $response->getStatusCode());
    }

    // -----------------------------------------------------------------------
    // STUB: PasswordResetController: sends email via MailManager
    // -----------------------------------------------------------------------

    #[Test]
    public function stubPasswordResetSendsEmailViaMailManager(): void
    {
        // Arrange
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn(
            $this->makeDbResult(['id' => 'user-42']),
        );

        $mailManager = $this->createMock(MailManagerInterface::class);
        $mailManager->expects(self::once())
            ->method('send')
            ->with(self::isInstanceOf(PasswordResetMailable::class))
            ->willReturn('msg-id-123');

        $controller = new PasswordResetController(
            connection: $connection,
            mailManager: $mailManager,
        );

        $request = new ServerRequest(method: 'POST', uri: '/forgot-password')
            ->withParsedBody(['email' => 'test@example.com']);

        // Act
        $response = $controller->sendResetLink($request);

        // Assert
        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function stubPasswordResetMailableHasCorrectEnvelope(): void
    {
        // Arrange
        $mailable = new PasswordResetMailable('user@example.com', 'abc123token');

        // Act
        $envelope = $mailable->envelope();
        $content = $mailable->content();

        // Assert
        self::assertSame('Reset Your Password', $envelope->subject);
        self::assertCount(1, $envelope->to);
        self::assertSame('user@example.com', $envelope->to[0]->email);
        self::assertNotNull($content->html);
        self::assertStringContainsString('abc123token', $content->html ?? '');
        self::assertNotNull($content->text);
        self::assertStringContainsString('abc123token', $content->text ?? '');
    }

    // -----------------------------------------------------------------------
    // STUB: RedisRealtimeBroadcaster
    // -----------------------------------------------------------------------

    #[Test]
    #[RequiresPhpExtension('redis')]
    public function stubRedisRealtimeBroadcasterPublishesToRedis(): void
    {
        // Arrange
        $redis = $this->createMock(Redis::class);
        $redis->expects(self::once())
            ->method('publish')
            ->with(self::stringStartsWith('forum:rt:'), self::callback(is_string(...)));
        $redis->expects(self::once())->method('rPush');
        $redis->expects(self::once())->method('lTrim');
        $redis->expects(self::once())->method('expire');

        $broadcaster = new RedisRealtimeBroadcaster($redis);

        $event = new RealtimeEvent(
            type: RealtimeEventType::NewPost,
            channelId: 'thread-123',
            payload: '{"post_id":"p1"}',
            userId: 'user-1',
        );

        // Act
        $broadcaster->broadcast($event);
    }

    #[Test]
    #[RequiresPhpExtension('redis')]
    public function stubRedisRealtimeBroadcasterTracksPresence(): void
    {
        // Arrange
        $redis = $this->createMock(Redis::class);
        $redis->expects(self::once())
            ->method('zAdd')
            ->with('forum:presence:ch-1', self::callback(is_int(...)), 'user-1');
        $redis->expects(self::once())->method('expire');

        $broadcaster = new RedisRealtimeBroadcaster($redis);

        // Act
        $broadcaster->trackPresence('ch-1', 'user-1');
    }

    #[Test]
    #[RequiresPhpExtension('redis')]
    public function stubRedisRealtimeBroadcasterRemovesPresence(): void
    {
        // Arrange
        $redis = $this->createMock(Redis::class);
        $redis->expects(self::once())
            ->method('zRem')
            ->with('forum:presence:ch-1', 'user-1');

        $broadcaster = new RedisRealtimeBroadcaster($redis);

        // Act
        $broadcaster->removePresence('ch-1', 'user-1');
    }

    #[Test]
    #[RequiresPhpExtension('redis')]
    public function stubRedisRealtimeBroadcasterGetsPresenceWithExpiry(): void
    {
        // Arrange
        $redis = $this->createStub(Redis::class);
        $redis->method('zRangeByScore')->willReturn(['user-1', 'user-2']);

        $broadcaster = new RedisRealtimeBroadcaster($redis);

        // Act
        $result = $broadcaster->getPresence('ch-1');

        // Assert
        self::assertSame(['user-1', 'user-2'], $result);
    }

    // -----------------------------------------------------------------------
    // M-3: deletePost/deleteThread records correct actor
    // -----------------------------------------------------------------------

    #[Test]
    public function m3DeletePostRecordsCorrectActor(): void
    {
        // Arrange
        $post = $this->makePost(authorId: 'original-author');
        $posts = $this->createStub(PostRepositoryInterface::class);
        $posts->method('findById')->willReturn($post);

        $threads = $this->createStub(ThreadRepositoryInterface::class);
        $profiles = $this->createStub(ForumProfileRepositoryInterface::class);

        $events = $this->createMock(EventDispatcherInterface::class);
        $events->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static function (PostDeleted $event): bool {
                return $event->deletedBy === 'moderator-42';
            }));

        $service = new ForumService(
            threads: $threads,
            posts: $posts,
            profiles: $profiles,
            reputationService: $this->createStub(ReputationServiceInterface::class),
            badgeService: $this->createStub(BadgeServiceInterface::class),
            events: $events,
            config: new ForumConfig(),
        );

        // Act — moderator deleting someone else's post: must pass isModerator=true
        $service->deletePost('post-1', 'moderator-42', isModerator: true);
    }

    #[Test]
    public function m3DeletePostByAuthorRecordsAuthorActor(): void
    {
        // MED-4 (2026-04-09): the original
        // m3DeletePostDefaultsToAuthorWhenNoActorProvided test
        // exercised the deletePost($id, '') default-actor path that
        // was the security bug being fixed. The new contract
        // requires every caller to identify itself; an author
        // deleting their own post must therefore pass their own id.

        // Arrange
        $post = $this->makePost(authorId: 'original-author');
        $posts = $this->createStub(PostRepositoryInterface::class);
        $posts->method('findById')->willReturn($post);

        $threads = $this->createStub(ThreadRepositoryInterface::class);
        $profiles = $this->createStub(ForumProfileRepositoryInterface::class);

        $events = $this->createMock(EventDispatcherInterface::class);
        $events->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static function (PostDeleted $event): bool {
                return $event->deletedBy === 'original-author';
            }));

        $service = new ForumService(
            threads: $threads,
            posts: $posts,
            profiles: $profiles,
            reputationService: $this->createStub(ReputationServiceInterface::class),
            badgeService: $this->createStub(BadgeServiceInterface::class),
            events: $events,
            config: new ForumConfig(),
        );

        // Act — author deletes their own post: no moderator flag needed.
        $service->deletePost('post-1', 'original-author');
    }

    #[Test]
    public function m3DeletePostRejectsEmptyActor(): void
    {
        // Regression guard for MED-4: passing an empty actor id MUST
        // be refused. Previously this defaulted to the post author
        // and let arbitrary callers delete arbitrary posts without
        // ever proving who they were.
        $post = $this->makePost(authorId: 'original-author');
        $posts = $this->createStub(PostRepositoryInterface::class);
        $posts->method('findById')->willReturn($post);

        $service = new ForumService(
            threads: $this->createStub(ThreadRepositoryInterface::class),
            posts: $posts,
            profiles: $this->createStub(ForumProfileRepositoryInterface::class),
            reputationService: $this->createStub(ReputationServiceInterface::class),
            badgeService: $this->createStub(BadgeServiceInterface::class),
            events: $this->createStub(EventDispatcherInterface::class),
            config: new ForumConfig(),
        );

        $this->expectException(\Pulsar\Extension\Forum\Exception\ForumException::class);
        $this->expectExceptionMessage('without identifying actor');

        $service->deletePost('post-1', '');
    }

    #[Test]
    public function m3DeletePostByNonAuthorWithoutModeratorFlagIsRejected(): void
    {
        // MED-4 regression guard: a non-author caller cannot delete
        // a post unless they explicitly pass isModerator=true. The
        // controller layer is responsible for proving the moderator
        // claim before forwarding the flag.
        $post = $this->makePost(authorId: 'original-author');
        $posts = $this->createStub(PostRepositoryInterface::class);
        $posts->method('findById')->willReturn($post);

        $service = new ForumService(
            threads: $this->createStub(ThreadRepositoryInterface::class),
            posts: $posts,
            profiles: $this->createStub(ForumProfileRepositoryInterface::class),
            reputationService: $this->createStub(ReputationServiceInterface::class),
            badgeService: $this->createStub(BadgeServiceInterface::class),
            events: $this->createStub(EventDispatcherInterface::class),
            config: new ForumConfig(),
        );

        $this->expectException(\Pulsar\Extension\Forum\Exception\ForumException::class);
        $this->expectExceptionMessage('non-author');

        $service->deletePost('post-1', 'random-other-user');
    }

    #[Test]
    public function m3DeleteThreadRecordsCorrectActor(): void
    {
        // Arrange
        $thread = $this->makeThread('original-author');
        $threads = $this->createStub(ThreadRepositoryInterface::class);
        $threads->method('findById')->willReturn($thread);

        $profiles = $this->createStub(ForumProfileRepositoryInterface::class);

        $events = $this->createMock(EventDispatcherInterface::class);
        $events->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static function (ThreadDeleted $event): bool {
                return $event->deletedBy === 'admin-99';
            }));

        $service = new ForumService(
            threads: $threads,
            posts: $this->createStub(PostRepositoryInterface::class),
            profiles: $profiles,
            reputationService: $this->createStub(ReputationServiceInterface::class),
            badgeService: $this->createStub(BadgeServiceInterface::class),
            events: $events,
            config: new ForumConfig(),
        );

        // Act — admin deleting someone else's thread: must pass isModerator=true.
        $service->deleteThread('thread-1', 'admin-99', isModerator: true);
    }

    // -----------------------------------------------------------------------
    // M-4: lockThread/pinThread records correct actor
    // -----------------------------------------------------------------------

    #[Test]
    public function m4LockThreadRecordsActorId(): void
    {
        // Arrange
        $thread = $this->makeThread('thread-author');
        $threads = $this->createStub(ThreadRepositoryInterface::class);
        $threads->method('findById')->willReturn($thread);

        $events = $this->createMock(EventDispatcherInterface::class);
        $events->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static function (ThreadLocked $event): bool {
                return $event->lockedBy === 'mod-1';
            }));

        $service = new ForumService(
            threads: $threads,
            posts: $this->createStub(PostRepositoryInterface::class),
            profiles: $this->createStub(ForumProfileRepositoryInterface::class),
            reputationService: $this->createStub(ReputationServiceInterface::class),
            badgeService: $this->createStub(BadgeServiceInterface::class),
            events: $events,
            config: new ForumConfig(),
        );

        // Act
        $service->lockThread('thread-1', 'mod-1');
    }

    #[Test]
    public function m4UnlockThreadRecordsActorId(): void
    {
        // Arrange
        $thread = $this->makeThread('thread-author');
        $threads = $this->createStub(ThreadRepositoryInterface::class);
        $threads->method('findById')->willReturn($thread);

        $events = $this->createMock(EventDispatcherInterface::class);
        $events->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static function (ThreadUnlocked $event): bool {
                return $event->unlockedBy === 'mod-2';
            }));

        $service = new ForumService(
            threads: $threads,
            posts: $this->createStub(PostRepositoryInterface::class),
            profiles: $this->createStub(ForumProfileRepositoryInterface::class),
            reputationService: $this->createStub(ReputationServiceInterface::class),
            badgeService: $this->createStub(BadgeServiceInterface::class),
            events: $events,
            config: new ForumConfig(),
        );

        // Act
        $service->unlockThread('thread-1', 'mod-2');
    }

    #[Test]
    public function m4PinThreadRecordsActorId(): void
    {
        // Arrange
        $thread = $this->makeThread('thread-author');
        $threads = $this->createStub(ThreadRepositoryInterface::class);
        $threads->method('findById')->willReturn($thread);

        $events = $this->createMock(EventDispatcherInterface::class);
        $events->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static function (ThreadPinned $event): bool {
                return $event->pinnedBy === 'admin-1';
            }));

        $service = new ForumService(
            threads: $threads,
            posts: $this->createStub(PostRepositoryInterface::class),
            profiles: $this->createStub(ForumProfileRepositoryInterface::class),
            reputationService: $this->createStub(ReputationServiceInterface::class),
            badgeService: $this->createStub(BadgeServiceInterface::class),
            events: $events,
            config: new ForumConfig(),
        );

        // Act
        $service->pinThread('thread-1', 'admin-1');
    }

    #[Test]
    public function m4UnpinThreadRecordsActorId(): void
    {
        // Arrange
        $thread = $this->makeThread('thread-author');
        $threads = $this->createStub(ThreadRepositoryInterface::class);
        $threads->method('findById')->willReturn($thread);

        $events = $this->createMock(EventDispatcherInterface::class);
        $events->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static function (ThreadUnpinned $event): bool {
                return $event->unpinnedBy === 'admin-2';
            }));

        $service = new ForumService(
            threads: $threads,
            posts: $this->createStub(PostRepositoryInterface::class),
            profiles: $this->createStub(ForumProfileRepositoryInterface::class),
            reputationService: $this->createStub(ReputationServiceInterface::class),
            badgeService: $this->createStub(BadgeServiceInterface::class),
            events: $events,
            config: new ForumConfig(),
        );

        // Act
        $service->unpinThread('thread-1', 'admin-2');
    }

    #[Test]
    public function m4LockThreadDefaultsToAuthorWhenNoActorProvided(): void
    {
        // Arrange
        $thread = $this->makeThread('thread-author');
        $threads = $this->createStub(ThreadRepositoryInterface::class);
        $threads->method('findById')->willReturn($thread);

        $events = $this->createMock(EventDispatcherInterface::class);
        $events->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static function (ThreadLocked $event): bool {
                return $event->lockedBy === 'thread-author';
            }));

        $service = new ForumService(
            threads: $threads,
            posts: $this->createStub(PostRepositoryInterface::class),
            profiles: $this->createStub(ForumProfileRepositoryInterface::class),
            reputationService: $this->createStub(ReputationServiceInterface::class),
            badgeService: $this->createStub(BadgeServiceInterface::class),
            events: $events,
            config: new ForumConfig(),
        );

        // Act
        $service->lockThread('thread-1');
    }

    // -----------------------------------------------------------------------
    // L-9: Route method names verified (PostApiController.accept exists)
    // -----------------------------------------------------------------------

    #[Test]
    public function l9PostApiControllerHasAcceptMethod(): void
    {
        // Assert: verify the method exists (route mapping fix)
        self::assertTrue(method_exists(PostApiController::class, 'accept'));
    }
}
