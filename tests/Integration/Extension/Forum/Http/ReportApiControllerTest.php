<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extension\Forum\Http;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Auth\Identity\TwoFactorStatus;
use Pulsar\Extension\Forum\Domain\ReportStatus;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Http\Controller\Api\ReportApiController;
use Pulsar\Extension\Forum\Report\PostReport;
use Pulsar\Extension\Forum\Report\ThreadReport;
use Pulsar\Extension\Forum\Service\ModerationServiceInterface;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(ReportApiController::class)]
final class ReportApiControllerTest extends TestCase
{
    private ModerationServiceInterface&Stub $moderationService;
    private ReportApiController $controller;

    protected function setUp(): void
    {
        $this->moderationService = $this->createStub(ModerationServiceInterface::class);
        $this->controller = new ReportApiController($this->moderationService);
    }

    #[Test]
    public function reportThreadRequiresAuthentication(): void
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/threads/thread-001/report',
            parsedBody: ['reason' => 'Spam'],
        );

        $this->expectException(ForumException::class);
        $this->controller->reportThread($request, 'thread-001');
    }

    #[Test]
    public function reportThreadReturns422ForMissingReason(): void
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/threads/thread-001/report',
            parsedBody: ['reason' => ''],
            attributes: ['identity' => $this->createIdentity('user-001')],
        );

        $response = $this->controller->reportThread($request, 'thread-001');

        self::assertSame(422, $response->getStatusCode());
        $data = $this->decodeBody($response);
        self::assertIsArray($data['details']);
        self::assertArrayHasKey('reason', $data['details']);
    }

    #[Test]
    public function reportThreadReturns400ForInvalidBody(): void
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/threads/thread-001/report',
            parsedBody: null,
            attributes: ['identity' => $this->createIdentity('user-001')],
        );

        $response = $this->controller->reportThread($request, 'thread-001');

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function reportThreadReturns201OnSuccess(): void
    {
        $report = new ThreadReport(
            id: 'tr-001',
            tenantId: null,
            threadId: 'thread-001',
            reporterId: 'user-001',
            reason: 'Spam content',
            status: ReportStatus::Pending,
            moderatorId: null,
            moderatorNote: null,
            createdAt: new DateTimeImmutable(),
            reviewedAt: null,
        );
        $this->moderationService->method('submitThreadReport')->willReturn($report);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/threads/thread-001/report',
            parsedBody: ['reason' => 'Spam content'],
            attributes: ['identity' => $this->createIdentity('user-001')],
        );

        $response = $this->controller->reportThread($request, 'thread-001');

        self::assertSame(201, $response->getStatusCode());
        $data = $this->decodeBody($response);
        self::assertIsArray($data['data']);
        self::assertSame('tr-001', $data['data']['id']);
        self::assertSame('thread-001', $data['data']['thread_id']);
        self::assertSame('pending', $data['data']['status']);
    }

    #[Test]
    public function reportPostRequiresAuthentication(): void
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/posts/post-001/report',
            parsedBody: ['reason' => 'Offensive'],
        );

        $this->expectException(ForumException::class);
        $this->controller->reportPost($request, 'post-001');
    }

    #[Test]
    public function reportPostReturns422ForMissingReason(): void
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/posts/post-001/report',
            parsedBody: [],
            attributes: ['identity' => $this->createIdentity('user-001')],
        );

        $response = $this->controller->reportPost($request, 'post-001');

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function reportPostReturns201OnSuccess(): void
    {
        $report = new PostReport(
            id: 'pr-001',
            tenantId: null,
            postId: 'post-001',
            reporterId: 'user-001',
            reason: 'Offensive language',
            status: ReportStatus::Pending,
            moderatorId: null,
            moderatorNote: null,
            createdAt: new DateTimeImmutable(),
            reviewedAt: null,
        );
        $this->moderationService->method('submitPostReport')->willReturn($report);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/posts/post-001/report',
            parsedBody: ['reason' => 'Offensive language'],
            attributes: ['identity' => $this->createIdentity('user-001')],
        );

        $response = $this->controller->reportPost($request, 'post-001');

        self::assertSame(201, $response->getStatusCode());
        $data = $this->decodeBody($response);
        self::assertIsArray($data['data']);
        self::assertSame('pr-001', $data['data']['id']);
        self::assertSame('post-001', $data['data']['post_id']);
        self::assertSame('pending', $data['data']['status']);
    }

    private function createIdentity(string $id): IdentityInterface
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('id')->willReturn($id);
        $identity->method('isAuthenticated')->willReturn(true);
        $identity->method('displayName')->willReturn('Test User');
        $identity->method('roles')->willReturn([]);
        $identity->method('hasRole')->willReturn(false);
        $identity->method('twoFactorStatus')->willReturn(TwoFactorStatus::Disabled);
        $identity->method('attributes')->willReturn([]);

        return $identity;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeBody(\Psr\Http\Message\ResponseInterface $response): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
