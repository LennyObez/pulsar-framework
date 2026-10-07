<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Http\Controller\Api;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Forum\Domain\ReportStatus;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Http\Controller\Api\ModerationApiController;
use Pulsar\Extension\Forum\Profile\ForumProfile;
use Pulsar\Extension\Forum\Report\PostReport;
use Pulsar\Extension\Forum\Report\PostReportRepositoryInterface;
use Pulsar\Extension\Forum\Report\ThreadReport;
use Pulsar\Extension\Forum\Report\ThreadReportRepositoryInterface;
use Pulsar\Extension\Forum\Service\ModerationServiceInterface;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(ModerationApiController::class)]
final class ModerationApiControllerTest extends TestCase
{
    private function makeIdentity(): IdentityInterface
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('id')->willReturn('mod-1');
        $identity->method('isAuthenticated')->willReturn(true);

        return $identity;
    }

    private function makeRequest(
        string $method = 'GET',
        array $queryParams = [],
        ?array $parsedBody = null,
    ): ServerRequest {
        $request = new ServerRequest(
            method: $method,
            uri: '/api/v1/forum/moderation/reports',
            headers: ['Accept' => 'application/json'],
            queryParams: $queryParams,
        )->withAttribute('identity', $this->makeIdentity());

        if ($parsedBody !== null) {
            $request = $request->withParsedBody($parsedBody);
        }

        return $request;
    }

    private function makeController(
        ?ThreadReportRepositoryInterface $threadRepo = null,
        ?PostReportRepositoryInterface $postRepo = null,
        ?ModerationServiceInterface $modService = null,
        ?GateInterface $gate = null,
    ): ModerationApiController {
        if ($threadRepo === null) {
            $threadRepo = $this->createStub(ThreadReportRepositoryInterface::class);
            $threadRepo->method('findByStatus')->willReturn(new PaginationResult([], 0, false, 20));
        }

        if ($postRepo === null) {
            $postRepo = $this->createStub(PostReportRepositoryInterface::class);
            $postRepo->method('findByStatus')->willReturn(new PaginationResult([], 0, false, 20));
        }

        if ($gate === null) {
            $gate = $this->createStub(GateInterface::class);
            $gate->method('allows')->willReturn(true);
        }

        return new ModerationApiController(
            threadReportRepository: $threadRepo,
            postReportRepository: $postRepo,
            moderationService: $modService ?? $this->createStub(ModerationServiceInterface::class),
            gate: $gate,
        );
    }

    #[Test]
    public function reportsWithoutAuthThrows(): void
    {
        $controller = $this->makeController();

        $request = new ServerRequest(
            method: 'GET',
            uri: '/api/v1/forum/moderation/reports',
            headers: ['Accept' => 'application/json'],
        );

        $this->expectException(ForumException::class);

        $controller->reports($request);
    }

    #[Test]
    public function reportsReturnsData(): void
    {
        $threadReport = ThreadReport::create(
            id: 'tr-1',
            threadId: 't-1',
            reporterId: 'user-1',
            reason: 'Spam',
        );

        $threadRepo = $this->createStub(ThreadReportRepositoryInterface::class);
        $threadRepo->method('findByStatus')->willReturn(new PaginationResult(
            items: [$threadReport],
            total: 1,
            hasMore: false,
            perPage: 20,
        ));

        $controller = $this->makeController(threadRepo: $threadRepo);

        $response = $controller->reports($this->makeRequest());

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertCount(1, $body['data']['thread_reports']);
        self::assertSame('tr-1', $body['data']['thread_reports'][0]['id']);
        self::assertSame('thread', $body['data']['thread_reports'][0]['type']);
        self::assertSame('pending', $body['filter']['status']);
    }

    #[Test]
    public function reviewReportWithoutBodyReturns400(): void
    {
        $controller = $this->makeController();

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/moderation/reports/tr-1/review',
            headers: ['Accept' => 'application/json'],
        )->withAttribute('identity', $this->makeIdentity());

        $response = $controller->reviewReport($request, 'tr-1');

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function reviewReportWithInvalidActionReturns422(): void
    {
        $controller = $this->makeController();

        $response = $controller->reviewReport(
            $this->makeRequest('POST', parsedBody: ['action' => 'invalid']),
            'tr-1',
        );

        self::assertSame(422, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Validation failed', $body['error']);
    }

    #[Test]
    public function reviewThreadReportReturnsSuccess(): void
    {
        $reviewed = new ThreadReport(
            id: 'tr-1',
            tenantId: null,
            threadId: 't-1',
            reporterId: 'user-1',
            reason: 'Spam',
            status: ReportStatus::Actioned,
            moderatorId: 'mod-1',
            moderatorNote: 'Done',
            createdAt: new DateTimeImmutable(),
            reviewedAt: new DateTimeImmutable(),
        );

        $modService = $this->createStub(ModerationServiceInterface::class);
        $modService->method('reviewThreadReport')->willReturn($reviewed);

        $controller = $this->makeController(modService: $modService);

        $response = $controller->reviewReport(
            $this->makeRequest('POST', parsedBody: ['action' => 'action', 'type' => 'thread', 'note' => 'Done']),
            'tr-1',
        );

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('tr-1', $body['data']['id']);
        self::assertSame('thread', $body['data']['type']);
        self::assertSame('actioned', $body['data']['status']);
    }

    #[Test]
    public function reviewPostReportReturnsSuccess(): void
    {
        $reviewed = new PostReport(
            id: 'pr-1',
            tenantId: null,
            postId: 'p-1',
            reporterId: 'user-1',
            reason: 'Off topic',
            status: ReportStatus::Dismissed,
            moderatorId: 'mod-1',
            moderatorNote: 'Not an issue',
            createdAt: new DateTimeImmutable(),
            reviewedAt: new DateTimeImmutable(),
        );

        $modService = $this->createStub(ModerationServiceInterface::class);
        $modService->method('reviewPostReport')->willReturn($reviewed);

        $controller = $this->makeController(modService: $modService);

        $response = $controller->reviewReport(
            $this->makeRequest('POST', parsedBody: ['action' => 'dismiss', 'type' => 'post']),
            'pr-1',
        );

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('post', $body['data']['type']);
        self::assertSame('dismissed', $body['data']['status']);
    }

    #[Test]
    public function reviewReportServiceExceptionReturns422(): void
    {
        $modService = $this->createStub(ModerationServiceInterface::class);
        $modService->method('reviewThreadReport')->willThrowException(
            new ForumException('Report not found'),
        );

        $controller = $this->makeController(modService: $modService);

        $response = $controller->reviewReport(
            $this->makeRequest('POST', parsedBody: ['action' => 'action']),
            'bad-id',
        );

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function banWithEmptyReasonReturns422(): void
    {
        $controller = $this->makeController();

        $response = $controller->ban(
            $this->makeRequest('POST', parsedBody: ['reason' => '']),
            'user-1',
        );

        self::assertSame(422, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Validation failed', $body['error']);
    }

    #[Test]
    public function banWithoutBodyReturns400(): void
    {
        $controller = $this->makeController();

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/moderation/ban/user-1',
            headers: ['Accept' => 'application/json'],
        )->withAttribute('identity', $this->makeIdentity());

        $response = $controller->ban($request, 'user-1');

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function banReturnsProfile(): void
    {
        $now = new DateTimeImmutable();
        $bannedProfile = new ForumProfile(
            id: 'profile-1',
            tenantId: null,
            userId: 'user-1',
            reputationScore: 100,
            postCount: 10,
            threadCount: 2,
            isBanned: true,
            banReason: 'Spam',
            bannedAt: $now,
            banExpiresAt: null,
            createdAt: $now,
            updatedAt: $now,
        );

        $modService = $this->createStub(ModerationServiceInterface::class);
        $modService->method('banUser')->willReturn($bannedProfile);

        $controller = $this->makeController(modService: $modService);

        $response = $controller->ban(
            $this->makeRequest('POST', parsedBody: ['reason' => 'Spam']),
            'user-1',
        );

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertTrue($body['data']['is_banned']);
        self::assertSame('Spam', $body['data']['ban_reason']);
    }

    #[Test]
    public function unbanReturnsProfile(): void
    {
        $now = new DateTimeImmutable();
        $profile = new ForumProfile(
            id: 'profile-1',
            tenantId: null,
            userId: 'user-1',
            reputationScore: 100,
            postCount: 10,
            threadCount: 2,
            isBanned: false,
            banReason: null,
            bannedAt: null,
            banExpiresAt: null,
            createdAt: $now,
            updatedAt: $now,
        );

        $modService = $this->createStub(ModerationServiceInterface::class);
        $modService->method('unbanUser')->willReturn($profile);

        $controller = $this->makeController(modService: $modService);

        $response = $controller->unban($this->makeRequest('POST'), 'user-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertFalse($body['data']['is_banned']);
    }

    #[Test]
    public function unbanServiceExceptionReturns422(): void
    {
        $modService = $this->createStub(ModerationServiceInterface::class);
        $modService->method('unbanUser')->willThrowException(
            new ForumException('User is not banned'),
        );

        $controller = $this->makeController(modService: $modService);

        $response = $controller->unban($this->makeRequest('POST'), 'user-1');

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function reportsThrowsWhenUserLacksModeratePermission(): void
    {
        // Arrange: gate denies forum.moderate
        $gate = $this->createStub(GateInterface::class);
        $gate->method('allows')->willReturn(false);

        $controller = $this->makeController(gate: $gate);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/api/v1/forum/moderation/reports',
            headers: ['Accept' => 'application/json'],
        )->withAttribute('identity', $this->makeIdentity());

        // Act & Assert
        $this->expectException(ForumException::class);
        $this->expectExceptionMessageIsOrContains('forum.moderate');

        $controller->reports($request);
    }

    #[Test]
    public function reviewReportThrowsWhenUserLacksModeratePermission(): void
    {
        // Arrange: gate denies forum.moderate
        $gate = $this->createStub(GateInterface::class);
        $gate->method('allows')->willReturn(false);

        $controller = $this->makeController(gate: $gate);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/moderation/reports/tr-1/review',
            headers: ['Accept' => 'application/json'],
        )->withAttribute('identity', $this->makeIdentity())
            ->withParsedBody(['action' => 'action']);

        // Act & Assert
        $this->expectException(ForumException::class);
        $this->expectExceptionMessageIsOrContains('forum.moderate');

        $controller->reviewReport($request, 'tr-1');
    }

    #[Test]
    public function banThrowsWhenUserLacksModeratePermission(): void
    {
        // Arrange: gate denies forum.moderate
        $gate = $this->createStub(GateInterface::class);
        $gate->method('allows')->willReturn(false);

        $controller = $this->makeController(gate: $gate);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/moderation/ban/user-1',
            headers: ['Accept' => 'application/json'],
        )->withAttribute('identity', $this->makeIdentity())
            ->withParsedBody(['reason' => 'Spam']);

        // Act & Assert
        $this->expectException(ForumException::class);
        $this->expectExceptionMessageIsOrContains('forum.moderate');

        $controller->ban($request, 'user-1');
    }

    #[Test]
    public function unbanThrowsWhenUserLacksModeratePermission(): void
    {
        // Arrange: gate denies forum.moderate
        $gate = $this->createStub(GateInterface::class);
        $gate->method('allows')->willReturn(false);

        $controller = $this->makeController(gate: $gate);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/moderation/unban/user-1',
            headers: ['Accept' => 'application/json'],
        )->withAttribute('identity', $this->makeIdentity());

        // Act & Assert
        $this->expectException(ForumException::class);
        $this->expectExceptionMessageIsOrContains('forum.moderate');

        $controller->unban($request, 'user-1');
    }
}
