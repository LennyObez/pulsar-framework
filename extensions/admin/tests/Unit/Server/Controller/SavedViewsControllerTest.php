<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Server\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Domain\SavedView;
use Pulsar\Extension\Admin\Features\SavedViews\SavedViewsHandler;
use Pulsar\Extension\Admin\Internal\Storage\SavedViewStoreInterface;
use Pulsar\Extension\Admin\Server\Controller\SavedViewsController;
use Pulsar\Http\Message\ServerRequest;

use function json_decode;

use const JSON_THROW_ON_ERROR;

final class SavedViewsControllerTest extends TestCase
{
    private SavedViewsController $controller;
    private SavedViewStoreInterface&Stub $store;

    protected function setUp(): void
    {
        $this->store = $this->createStub(SavedViewStoreInterface::class);
        $handler = new SavedViewsHandler($this->store);
        $this->controller = new SavedViewsController($handler);
    }

    #[Test]
    public function listReturnsViewsForResource(): void
    {
        $views = [
            new SavedView('v-1', 'posts', 'Published', ['status' => 'published'], ['created_at' => 'desc'], 25, 'admin', true, 1700000000),
            new SavedView('v-2', 'posts', 'Drafts', ['status' => 'draft'], [], 10, 'admin', false, 1700000100),
        ];
        $this->store->method('listForResource')->willReturn($views);

        $request = new ServerRequest(method: 'GET', uri: '/admin/views/posts');

        $response = $this->controller->list('posts');

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertCount(2, $body['views']);
        self::assertSame('v-1', $body['views'][0]['id']);
        self::assertSame('Published', $body['views'][0]['label']);
        self::assertTrue($body['views'][0]['is_default']);
        self::assertSame(25, $body['views'][0]['per_page']);
        self::assertSame('v-2', $body['views'][1]['id']);
        self::assertFalse($body['views'][1]['is_default']);
    }

    #[Test]
    public function listReturnsEmptyArrayWhenNoViews(): void
    {
        $this->store->method('listForResource')->willReturn([]);

        $request = new ServerRequest(method: 'GET', uri: '/admin/views/posts');

        $response = $this->controller->list('posts');

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame([], $body['views']);
    }

    #[Test]
    public function storeReturns201OnSuccess(): void
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: '/admin/views/posts',
            parsedBody: [
                'label' => 'My View',
                'filters' => ['status' => 'published'],
                'sort' => ['title' => 'asc'],
                'per_page' => 50,
                'is_default' => true,
            ],
        );

        $response = $this->controller->store($request, 'posts');

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertTrue($body['success']);
    }

    #[Test]
    public function storeHandlesNonArrayFilters(): void
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: '/admin/views/posts',
            parsedBody: [
                'label' => 'Test',
                'filters' => 'not-an-array',
                'sort' => 'not-an-array',
            ],
        );

        $response = $this->controller->store($request, 'posts');

        self::assertSame(201, $response->getStatusCode());
    }

    #[Test]
    public function storeHandlesNullParsedBody(): void
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: '/admin/views/posts',
        );

        $response = $this->controller->store($request, 'posts');

        self::assertSame(201, $response->getStatusCode());
    }

    #[Test]
    public function deleteReturnsSuccessResponse(): void
    {
        $request = new ServerRequest(method: 'DELETE', uri: '/admin/views/posts/v-1');

        $response = $this->controller->delete('v-1');

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertTrue($body['success']);
    }

    #[Test]
    public function listSerializesAllViewFields(): void
    {
        $views = [
            new SavedView('v-1', 'posts', 'All', ['key' => 'val'], ['name' => 'asc'], 15, 'user1', false, 1700000000),
        ];
        $this->store->method('listForResource')->willReturn($views);

        $request = new ServerRequest(method: 'GET', uri: '/admin/views/posts');

        $response = $this->controller->list('posts');

        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        $view = $body['views'][0];
        self::assertSame('v-1', $view['id']);
        self::assertSame('All', $view['label']);
        self::assertSame(['key' => 'val'], $view['filters']);
        self::assertSame(['name' => 'asc'], $view['sort']);
        self::assertSame(15, $view['per_page']);
        self::assertFalse($view['is_default']);
    }
}
