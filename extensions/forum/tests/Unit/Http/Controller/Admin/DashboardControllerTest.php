<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Http\Controller\Admin;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Exception\AuthenticationException;
use Pulsar\Auth\Exception\AuthorizationException;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Forum\Domain\ThreadStatus;
use Pulsar\Extension\Forum\Domain\ThreadType;
use Pulsar\Extension\Forum\Http\Controller\Admin\DashboardController;
use Pulsar\Extension\Forum\Profile\ForumProfileRepositoryInterface;
use Pulsar\Extension\Forum\Report\ThreadReportRepositoryInterface;
use Pulsar\Extension\Forum\Thread\Thread;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(DashboardController::class)]
final class DashboardControllerTest extends TestCase
{
    private function makeIdentity(string $id = 'admin-1'): IdentityInterface
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('id')->willReturn($id);
        $identity->method('isAuthenticated')->willReturn(true);

        return $identity;
    }

    private function makeController(
        ?GateInterface $gate = null,
        ?ThreadRepositoryInterface $threadRepo = null,
        ?ThreadReportRepositoryInterface $reportRepo = null,
        ?ForumProfileRepositoryInterface $profileRepo = null,
    ): DashboardController {
        if ($threadRepo === null) {
            $threadRepo = $this->createStub(ThreadRepositoryInterface::class);
            $threadRepo->method('findRecent')->willReturn(new PaginationResult([], 0, false, 10));
        }

        if ($reportRepo === null) {
            $reportRepo = $this->createStub(ThreadReportRepositoryInterface::class);
            $reportRepo->method('findByStatus')->willReturn(new PaginationResult([], 0, false, 5));
        }

        if ($profileRepo === null) {
            $profileRepo = $this->createStub(ForumProfileRepositoryInterface::class);
            $profileRepo->method('findTopContributors')->willReturn(new PaginationResult([], 0, false, 5));
        }

        return new DashboardController(
            threadRepository: $threadRepo,
            reportRepository: $reportRepo,
            profileRepository: $profileRepo,
            gate: $gate ?? $this->createStub(GateInterface::class),
        );
    }

    #[Test]
    public function indexWithoutAuthThrows(): void
    {
        $controller = $this->makeController();

        $request = new ServerRequest(
            method: 'GET',
            uri: '/admin/forum',
            headers: ['Accept' => 'application/json'],
        );

        $this->expectException(AuthenticationException::class);

        $controller->index($request);
    }

    #[Test]
    public function indexWithoutPermissionThrows(): void
    {
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $controller = $this->makeController(gate: $gate);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/admin/forum',
            headers: ['Accept' => 'application/json'],
        )->withAttribute('identity', $this->makeIdentity());

        $this->expectException(AuthorizationException::class);

        $controller->index($request);
    }

    #[Test]
    public function indexReturnsJsonDashboardData(): void
    {
        $now = new DateTimeImmutable('2025-01-15 12:00:00');

        $thread = new Thread(
            id: 'thread-1',
            tenantId: null,
            categoryId: 'cat-1',
            authorId: 'user-1',
            title: 'Test Thread',
            slug: 'test-thread',
            type: ThreadType::Discussion,
            status: ThreadStatus::Open,
            isPinned: false,
            isLocked: false,
            solvedPostId: null,
            replyCount: 5,
            viewCount: 100,
            voteScore: 10,
            lastActivityAt: $now,
            ipHash: 'abc',
            userAgentHash: 'def',
            createdAt: $now,
            updatedAt: $now,
            deletedAt: null,
        );

        $threadRepo = $this->createStub(ThreadRepositoryInterface::class);
        $threadRepo->method('findRecent')->willReturn(new PaginationResult(
            items: [$thread],
            total: 42,
            hasMore: true,
            perPage: 10,
        ));

        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(false);

        $controller = $this->makeController(gate: $gate, threadRepo: $threadRepo);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/admin/forum',
            headers: ['Accept' => 'application/json'],
        )->withAttribute('identity', $this->makeIdentity());

        $response = $controller->index($request);

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('recent_threads', $body);
        self::assertArrayHasKey('pending_reports_count', $body);
        self::assertArrayHasKey('top_contributors', $body);
        self::assertArrayHasKey('stats', $body);
        self::assertCount(1, $body['recent_threads']);
        self::assertSame('Test Thread', $body['recent_threads'][0]['title']);
        self::assertSame(42, $body['stats']['total_threads']);
    }
}
