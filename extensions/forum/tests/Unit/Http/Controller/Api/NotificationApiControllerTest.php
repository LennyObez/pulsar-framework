<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Http\Controller\Api;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Http\Controller\Api\NotificationApiController;
use Pulsar\Extension\Forum\Notification\ForumNotification;
use Pulsar\Extension\Forum\Notification\ForumNotificationRepositoryInterface;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(NotificationApiController::class)]
final class NotificationApiControllerTest extends TestCase
{
    private function makeIdentity(string $id = 'user-1'): IdentityInterface
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('id')->willReturn($id);
        $identity->method('isAuthenticated')->willReturn(true);

        return $identity;
    }

    private function makeNotification(
        string $id = 'notif-1',
        string $userId = 'user-1',
        bool $isRead = false,
    ): ForumNotification {
        return new ForumNotification(
            id: $id,
            userId: $userId,
            type: 'thread_reply',
            title: 'New reply',
            body: 'Someone replied to your thread',
            url: '/forum/threads/t-1',
            isRead: $isRead,
            data: ['thread_id' => 't-1'],
            createdAt: new DateTimeImmutable('2025-06-01 10:00:00'),
        );
    }

    private function makeRequest(
        string $method = 'GET',
        array $queryParams = [],
    ): ServerRequest {
        return new ServerRequest(
            method: $method,
            uri: '/api/v1/forum/notifications',
            headers: ['Accept' => 'application/json'],
            queryParams: $queryParams,
        )->withAttribute('identity', $this->makeIdentity());
    }

    #[Test]
    public function listWithoutAuthThrows(): void
    {
        $controller = new NotificationApiController(
            $this->createStub(ForumNotificationRepositoryInterface::class),
        );

        $request = new ServerRequest(
            method: 'GET',
            uri: '/api/v1/forum/notifications',
            headers: ['Accept' => 'application/json'],
        );

        $this->expectException(ForumException::class);

        $controller->list($request);
    }

    #[Test]
    public function listReturnsNotifications(): void
    {
        $notif = $this->makeNotification();

        $repo = $this->createStub(ForumNotificationRepositoryInterface::class);
        $repo->method('findByUser')->willReturn(new PaginationResult(
            items: [$notif],
            total: 1,
            hasMore: false,
            perPage: 20,
        ));

        $controller = new NotificationApiController($repo);

        $response = $controller->list($this->makeRequest());

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertCount(1, $body['data']);
        self::assertSame('notif-1', $body['data'][0]['id']);
        self::assertSame('thread_reply', $body['data'][0]['type']);
        self::assertSame('New reply', $body['data'][0]['title']);
        self::assertFalse($body['data'][0]['is_read']);
        self::assertArrayHasKey('meta', $body);
    }

    #[Test]
    public function listReturnsEmptyData(): void
    {
        $repo = $this->createStub(ForumNotificationRepositoryInterface::class);
        $repo->method('findByUser')->willReturn(new PaginationResult([], 0, false, 20));

        $controller = new NotificationApiController($repo);

        $response = $controller->list($this->makeRequest());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertCount(0, $body['data']);
    }

    #[Test]
    public function markReadReturns404WhenNotFound(): void
    {
        $repo = $this->createStub(ForumNotificationRepositoryInterface::class);
        $repo->method('findById')->willReturn(null);

        $controller = new NotificationApiController($repo);

        $response = $controller->markRead($this->makeRequest('PATCH'), 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function markReadReturns404WhenOwnerMismatch(): void
    {
        $notif = $this->makeNotification(userId: 'other-user');

        $repo = $this->createStub(ForumNotificationRepositoryInterface::class);
        $repo->method('findById')->willReturn($notif);

        $controller = new NotificationApiController($repo);

        $response = $controller->markRead($this->makeRequest('PATCH'), 'notif-1');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function markReadReturnsSuccess(): void
    {
        $notif = $this->makeNotification();

        $repo = $this->createStub(ForumNotificationRepositoryInterface::class);
        $repo->method('findById')->willReturn($notif);

        $controller = new NotificationApiController($repo);

        $response = $controller->markRead($this->makeRequest('PATCH'), 'notif-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('notif-1', $body['data']['id']);
        self::assertTrue($body['data']['is_read']);
    }

    #[Test]
    public function markAllReadReturnsSuccess(): void
    {
        $repo = $this->createStub(ForumNotificationRepositoryInterface::class);

        $controller = new NotificationApiController($repo);

        $response = $controller->markAllRead($this->makeRequest('POST'));

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('all_read', $body['data']['status']);
    }

    #[Test]
    public function unreadCountReturnsCount(): void
    {
        $repo = $this->createStub(ForumNotificationRepositoryInterface::class);
        $repo->method('countUnread')->willReturn(5);

        $controller = new NotificationApiController($repo);

        $response = $controller->unreadCount($this->makeRequest());

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(5, $body['data']['unread_count']);
    }
}
