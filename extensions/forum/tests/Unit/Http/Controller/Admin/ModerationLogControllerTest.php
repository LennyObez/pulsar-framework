<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Http\Controller\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Exception\AuthorizationException;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Forum\Domain\ModerationAction;
use Pulsar\Extension\Forum\Http\Controller\Admin\ModerationLogController;
use Pulsar\Extension\Forum\Report\ForumModerationLog;
use Pulsar\Extension\Forum\Report\ForumModerationLogRepositoryInterface;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(ModerationLogController::class)]
final class ModerationLogControllerTest extends TestCase
{
    private function makeIdentity(): IdentityInterface
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('id')->willReturn('admin-1');
        $identity->method('isAuthenticated')->willReturn(true);

        return $identity;
    }

    private function makeAllowGate(): GateInterface
    {
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(false);

        return $gate;
    }

    #[Test]
    public function indexWithoutPermissionThrows(): void
    {
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $controller = new ModerationLogController(
            $this->createStub(ForumModerationLogRepositoryInterface::class),
            $gate,
        );

        $request = new ServerRequest(
            method: 'GET',
            uri: '/admin/forum/moderation-log',
            headers: ['Accept' => 'application/json'],
        )->withAttribute('identity', $this->makeIdentity());

        $this->expectException(AuthorizationException::class);

        $controller->index($request);
    }

    #[Test]
    public function indexReturnsRecentLogs(): void
    {
        $log = ForumModerationLog::create(
            moderatorId: 'mod-1',
            action: ModerationAction::Delete,
            targetType: 'post',
            targetId: 'post-1',
            reason: 'Spam content',
        );

        $logRepo = $this->createStub(ForumModerationLogRepositoryInterface::class);
        $logRepo->method('findRecent')->willReturn(new PaginationResult(
            items: [$log],
            total: 1,
            hasMore: false,
            perPage: 20,
        ));

        $controller = new ModerationLogController($logRepo, $this->makeAllowGate());

        $request = new ServerRequest(
            method: 'GET',
            uri: '/admin/forum/moderation-log',
            headers: ['Accept' => 'application/json'],
        )->withAttribute('identity', $this->makeIdentity());

        $response = $controller->index($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertCount(1, $body['data']);
        self::assertSame('mod-1', $body['data'][0]['moderator_id']);
        self::assertSame('delete', $body['data'][0]['action']);
        self::assertSame('post', $body['data'][0]['target_type']);
        self::assertNull($body['filter']['moderator_id']);
        self::assertArrayHasKey('pagination', $body);
    }

    #[Test]
    public function indexFiltersByModeratorId(): void
    {
        $logRepo = $this->createStub(ForumModerationLogRepositoryInterface::class);
        $logRepo->method('findByModerator')->willReturn(new PaginationResult(
            items: [],
            total: 0,
            hasMore: false,
            perPage: 20,
        ));

        $controller = new ModerationLogController($logRepo, $this->makeAllowGate());

        $request = new ServerRequest(
            method: 'GET',
            uri: '/admin/forum/moderation-log',
            headers: ['Accept' => 'application/json'],
            queryParams: ['moderator_id' => 'mod-1'],
        )->withAttribute('identity', $this->makeIdentity());

        $response = $controller->index($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('mod-1', $body['filter']['moderator_id']);
    }

    #[Test]
    public function indexIgnoresEmptyModeratorFilter(): void
    {
        $logRepo = $this->createStub(ForumModerationLogRepositoryInterface::class);
        $logRepo->method('findRecent')->willReturn(new PaginationResult(
            items: [],
            total: 0,
            hasMore: false,
            perPage: 20,
        ));

        $controller = new ModerationLogController($logRepo, $this->makeAllowGate());

        $request = new ServerRequest(
            method: 'GET',
            uri: '/admin/forum/moderation-log',
            headers: ['Accept' => 'application/json'],
            queryParams: ['moderator_id' => ''],
        )->withAttribute('identity', $this->makeIdentity());

        $response = $controller->index($request);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertNull($body['filter']['moderator_id']);
    }
}
