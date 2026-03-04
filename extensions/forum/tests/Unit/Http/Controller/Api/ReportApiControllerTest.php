<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Http\Controller\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Identity\IdentityInterface;
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
    private function makeIdentity(string $id = 'user-1'): IdentityInterface
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('id')->willReturn($id);
        $identity->method('isAuthenticated')->willReturn(true);

        return $identity;
    }

    private function makeAuthRequest(string $method, string $uri, ?array $body = null): ServerRequest
    {
        $request = new ServerRequest(
            method: $method,
            uri: $uri,
        )->withAttribute('identity', $this->makeIdentity());

        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }

        return $request;
    }

    #[Test]
    public function reportThreadWithoutAuthThrows(): void
    {
        $controller = new ReportApiController(
            $this->createStub(ModerationServiceInterface::class),
        );

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/threads/t1/report',
        )->withParsedBody(['reason' => 'Spam']);

        $this->expectException(ForumException::class);

        $controller->reportThread($request, 't1');
    }

    #[Test]
    public function reportThreadWithNullBodyReturns400(): void
    {
        $controller = new ReportApiController(
            $this->createStub(ModerationServiceInterface::class),
        );

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/threads/t1/report',
        )->withAttribute('identity', $this->makeIdentity());

        $response = $controller->reportThread($request, 't1');

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function reportThreadWithEmptyReasonReturns422(): void
    {
        $controller = new ReportApiController(
            $this->createStub(ModerationServiceInterface::class),
        );

        $request = $this->makeAuthRequest('POST', '/api/v1/forum/threads/t1/report', ['reason' => '']);

        $response = $controller->reportThread($request, 't1');

        self::assertSame(422, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('reason', $body['details']);
    }

    #[Test]
    public function reportThreadReturns201OnSuccess(): void
    {
        $report = ThreadReport::create(
            id: 'report-1',
            threadId: 't1',
            reporterId: 'user-1',
            reason: 'Spam content',
        );

        $modService = $this->createStub(ModerationServiceInterface::class);
        $modService->method('submitThreadReport')->willReturn($report);

        $controller = new ReportApiController($modService);

        $request = $this->makeAuthRequest('POST', '/api/v1/forum/threads/t1/report', ['reason' => 'Spam content']);

        $response = $controller->reportThread($request, 't1');

        self::assertSame(201, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('report-1', $body['data']['id']);
        self::assertSame('t1', $body['data']['thread_id']);
        self::assertSame(ReportStatus::Pending->value, $body['data']['status']);
    }

    #[Test]
    public function reportPostWithEmptyReasonReturns422(): void
    {
        $controller = new ReportApiController(
            $this->createStub(ModerationServiceInterface::class),
        );

        $request = $this->makeAuthRequest('POST', '/api/v1/forum/posts/p1/report', ['reason' => '']);

        $response = $controller->reportPost($request, 'p1');

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function reportPostReturns201OnSuccess(): void
    {
        $report = PostReport::create(
            id: 'report-2',
            postId: 'p1',
            reporterId: 'user-1',
            reason: 'Offensive',
        );

        $modService = $this->createStub(ModerationServiceInterface::class);
        $modService->method('submitPostReport')->willReturn($report);

        $controller = new ReportApiController($modService);

        $request = $this->makeAuthRequest('POST', '/api/v1/forum/posts/p1/report', ['reason' => 'Offensive']);

        $response = $controller->reportPost($request, 'p1');

        self::assertSame(201, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('report-2', $body['data']['id']);
        self::assertSame('p1', $body['data']['post_id']);
    }

    #[Test]
    public function reportThreadServiceExceptionReturns422(): void
    {
        $modService = $this->createStub(ModerationServiceInterface::class);
        $modService->method('submitThreadReport')->willThrowException(
            ForumException::unauthorized('Already reported'),
        );

        $controller = new ReportApiController($modService);

        $request = $this->makeAuthRequest('POST', '/api/v1/forum/threads/t1/report', ['reason' => 'Spam']);

        $response = $controller->reportThread($request, 't1');

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function reportPostWithNullBodyReturns400(): void
    {
        $controller = new ReportApiController(
            $this->createStub(ModerationServiceInterface::class),
        );

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/posts/p1/report',
        )->withAttribute('identity', $this->makeIdentity());

        $response = $controller->reportPost($request, 'p1');

        self::assertSame(400, $response->getStatusCode());
    }
}
