<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Http\Controller\Admin;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Exception\AuthorizationException;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Forum\Domain\ReportStatus;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Http\Controller\Admin\ModerationController;
use Pulsar\Extension\Forum\Report\PostReportRepositoryInterface;
use Pulsar\Extension\Forum\Report\ThreadReport;
use Pulsar\Extension\Forum\Report\ThreadReportRepositoryInterface;
use Pulsar\Extension\Forum\Service\ModerationServiceInterface;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(ModerationController::class)]
final class ModerationControllerTest extends TestCase
{
    private function makeIdentity(): IdentityInterface
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('id')->willReturn('mod-1');
        $identity->method('isAuthenticated')->willReturn(true);

        return $identity;
    }

    private function makeAllowGate(): GateInterface
    {
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(false);

        return $gate;
    }

    private function makeController(
        ?GateInterface $gate = null,
        ?ThreadReportRepositoryInterface $threadReportRepo = null,
        ?PostReportRepositoryInterface $postReportRepo = null,
        ?ModerationServiceInterface $modService = null,
    ): ModerationController {
        if ($threadReportRepo === null) {
            $threadReportRepo = $this->createStub(ThreadReportRepositoryInterface::class);
            $threadReportRepo->method('findByStatus')->willReturn(new PaginationResult([], 0, false, 20));
        }

        if ($postReportRepo === null) {
            $postReportRepo = $this->createStub(PostReportRepositoryInterface::class);
            $postReportRepo->method('findByStatus')->willReturn(new PaginationResult([], 0, false, 20));
        }

        return new ModerationController(
            threadReportRepository: $threadReportRepo,
            postReportRepository: $postReportRepo,
            moderationService: $modService ?? $this->createStub(ModerationServiceInterface::class),
            gate: $gate ?? $this->makeAllowGate(),
        );
    }

    #[Test]
    public function indexWithoutPermissionThrows(): void
    {
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $controller = $this->makeController(gate: $gate);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/admin/forum/moderation',
            headers: ['Accept' => 'application/json'],
        )->withAttribute('identity', $this->makeIdentity());

        $this->expectException(AuthorizationException::class);

        $controller->index($request);
    }

    #[Test]
    public function indexReturnsReportsData(): void
    {
        $now = new DateTimeImmutable();

        $threadReport = ThreadReport::create(
            id: 'tr-1',
            threadId: 't1',
            reporterId: 'user-1',
            reason: 'Spam',
        );

        $threadReportRepo = $this->createStub(ThreadReportRepositoryInterface::class);
        $threadReportRepo->method('findByStatus')->willReturn(new PaginationResult(
            items: [$threadReport],
            total: 1,
            hasMore: false,
            perPage: 20,
        ));

        $controller = $this->makeController(threadReportRepo: $threadReportRepo);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/admin/forum/moderation',
            headers: ['Accept' => 'application/json'],
        )->withAttribute('identity', $this->makeIdentity());

        $response = $controller->index($request);

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('thread_reports', $body);
        self::assertArrayHasKey('post_reports', $body);
        self::assertArrayHasKey('filter', $body);
        self::assertCount(1, $body['thread_reports']);
        self::assertSame('tr-1', $body['thread_reports'][0]['id']);
    }

    #[Test]
    public function reviewThreadReportWithInvalidActionReturns400(): void
    {
        $controller = $this->makeController();

        $request = new ServerRequest(
            method: 'POST',
            uri: '/admin/forum/moderation/thread-reports/tr-1',
            headers: ['Accept' => 'application/json'],
        )->withAttribute('identity', $this->makeIdentity())
            ->withParsedBody(['action' => 'invalid']);

        $response = $controller->reviewThreadReport($request, 'tr-1');

        self::assertSame(400, $response->getStatusCode());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function validActionProvider(): iterable
    {
        yield 'action' => ['action', ReportStatus::Actioned->value];
        yield 'dismiss' => ['dismiss', ReportStatus::Dismissed->value];
    }

    #[Test]
    #[DataProvider('validActionProvider')]
    public function reviewThreadReportWithValidAction(string $action, string $expectedStatus): void
    {
        $report = ThreadReport::create(
            id: 'tr-1',
            threadId: 't1',
            reporterId: 'user-1',
            reason: 'Spam',
        );

        // Use clone-with to set the expected status
        $reviewedReport = new ThreadReport(
            id: $report->id,
            tenantId: $report->tenantId,
            threadId: $report->threadId,
            reporterId: $report->reporterId,
            reason: $report->reason,
            status: ReportStatus::from($expectedStatus),
            moderatorId: 'mod-1',
            moderatorNote: 'Reviewed',
            createdAt: $report->createdAt,
            reviewedAt: new DateTimeImmutable(),
        );

        $modService = $this->createStub(ModerationServiceInterface::class);
        $modService->method('reviewThreadReport')->willReturn($reviewedReport);

        $controller = $this->makeController(modService: $modService);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/admin/forum/moderation/thread-reports/tr-1',
            headers: ['Accept' => 'application/json'],
        )->withAttribute('identity', $this->makeIdentity())
            ->withParsedBody(['action' => $action, 'note' => 'Reviewed']);

        $response = $controller->reviewThreadReport($request, 'tr-1');

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('tr-1', $body['data']['id']);
        self::assertSame($expectedStatus, $body['data']['status']);
        self::assertSame($action, $body['data']['action']);
    }

    #[Test]
    public function reviewPostReportWithInvalidActionReturns400(): void
    {
        $controller = $this->makeController();

        $request = new ServerRequest(
            method: 'POST',
            uri: '/admin/forum/moderation/post-reports/pr-1',
            headers: ['Accept' => 'application/json'],
        )->withAttribute('identity', $this->makeIdentity())
            ->withParsedBody(['action' => 'invalid']);

        $response = $controller->reviewPostReport($request, 'pr-1');

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function reviewThreadReportServiceExceptionReturns422(): void
    {
        $modService = $this->createStub(ModerationServiceInterface::class);
        $modService->method('reviewThreadReport')->willThrowException(
            ForumException::invalidTransition('pending', 'actioned'),
        );

        $controller = $this->makeController(modService: $modService);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/admin/forum/moderation/thread-reports/tr-1',
            headers: ['Accept' => 'application/json'],
        )->withAttribute('identity', $this->makeIdentity())
            ->withParsedBody(['action' => 'action']);

        $response = $controller->reviewThreadReport($request, 'tr-1');

        self::assertSame(422, $response->getStatusCode());
    }
}
