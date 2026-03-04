<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Server\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Contracts\DataResourceInterface;
use Pulsar\Extension\Admin\Contracts\ResourceMutatorInterface;
use Pulsar\Extension\Admin\Contracts\ResourceRegistryInterface;
use Pulsar\Extension\Admin\Domain\ActionResult;
use Pulsar\Extension\Admin\Domain\ResourceOperation;
use Pulsar\Extension\Admin\Features\BulkAction\BulkActionHandler;
use Pulsar\Extension\Admin\Internal\Storage\ActionHistoryStoreInterface;
use Pulsar\Extension\Admin\Server\Controller\BulkActionController;
use Pulsar\Http\Message\ServerRequest;

use function json_decode;

use const JSON_THROW_ON_ERROR;

final class BulkActionControllerTest extends TestCase
{
    private BulkActionController $controller;
    private ResourceMutatorInterface&Stub $mutator;

    protected function setUp(): void
    {
        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('operations')->willReturn([ResourceOperation::BulkAction]);

        $registry = $this->createStub(ResourceRegistryInterface::class);
        $registry->method('get')->willReturn($resource);

        $this->mutator = $this->createStub(ResourceMutatorInterface::class);
        $actionHistory = $this->createStub(ActionHistoryStoreInterface::class);

        $handler = new BulkActionHandler($registry, $this->mutator, $actionHistory);
        $this->controller = new BulkActionController($handler);
    }

    #[Test]
    public function executeReturns200OnSuccess(): void
    {
        $this->mutator->method('bulkAction')->willReturn(
            ActionResult::success('3 records deleted', ['count' => 3]),
        );

        $request = new ServerRequest(
            method: 'POST',
            uri: '/admin/bulk/posts',
            parsedBody: [
                'action' => 'delete',
                'ids' => ['id-1', 'id-2', 'id-3'],
            ],
        );

        $response = $this->controller->execute($request, 'posts');

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertTrue($body['success']);
        self::assertSame('3 records deleted', $body['message']);
    }

    #[Test]
    public function executeReturns422OnFailure(): void
    {
        $this->mutator->method('bulkAction')->willReturn(
            ActionResult::failure('Permission denied'),
        );

        $request = new ServerRequest(
            method: 'POST',
            uri: '/admin/bulk/posts',
            parsedBody: [
                'action' => 'publish',
                'ids' => ['id-1'],
            ],
        );

        $response = $this->controller->execute($request, 'posts');

        self::assertSame(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertFalse($body['success']);
    }

    #[Test]
    public function executeHandlesNonArrayIds(): void
    {
        $this->mutator->method('bulkAction')->willReturn(ActionResult::success('OK'));

        $request = new ServerRequest(
            method: 'POST',
            uri: '/admin/bulk/posts',
            parsedBody: [
                'action' => 'delete',
                'ids' => 'not-an-array',
            ],
        );

        $response = $this->controller->execute($request, 'posts');

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function executeHandlesNonArrayParameters(): void
    {
        $this->mutator->method('bulkAction')->willReturn(ActionResult::success('OK'));

        $request = new ServerRequest(
            method: 'POST',
            uri: '/admin/bulk/posts',
            parsedBody: [
                'action' => 'update',
                'ids' => ['id-1'],
                'parameters' => 'not-an-array',
            ],
        );

        $response = $this->controller->execute($request, 'posts');

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function executeHandlesNullParsedBody(): void
    {
        $this->mutator->method('bulkAction')->willReturn(ActionResult::success('OK'));

        $request = new ServerRequest(
            method: 'POST',
            uri: '/admin/bulk/posts',
        );

        $response = $this->controller->execute($request, 'posts');

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function executeHandlesNonStringAction(): void
    {
        $this->mutator->method('bulkAction')->willReturn(ActionResult::success('OK'));

        $request = new ServerRequest(
            method: 'POST',
            uri: '/admin/bulk/posts',
            parsedBody: [
                'action' => ['nested'],
                'ids' => [],
            ],
        );

        $response = $this->controller->execute($request, 'posts');

        self::assertSame(200, $response->getStatusCode());
    }
}
