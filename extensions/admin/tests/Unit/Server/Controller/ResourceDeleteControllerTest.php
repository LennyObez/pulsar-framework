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
use Pulsar\Extension\Admin\Features\DeleteResource\DeleteResourceHandler;
use Pulsar\Extension\Admin\Internal\Storage\ActionHistoryStoreInterface;
use Pulsar\Extension\Admin\Server\Controller\ResourceDeleteController;
use Pulsar\Http\Message\ServerRequest;

use function json_decode;

use const JSON_THROW_ON_ERROR;

final class ResourceDeleteControllerTest extends TestCase
{
    private ResourceDeleteController $controller;
    private ResourceMutatorInterface&Stub $mutator;

    protected function setUp(): void
    {
        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('operations')->willReturn([ResourceOperation::Delete]);

        $registry = $this->createStub(ResourceRegistryInterface::class);
        $registry->method('get')->willReturn($resource);

        $this->mutator = $this->createStub(ResourceMutatorInterface::class);
        $actionHistory = $this->createStub(ActionHistoryStoreInterface::class);

        $handler = new DeleteResourceHandler($registry, $this->mutator, $actionHistory);
        $this->controller = new ResourceDeleteController($handler);
    }

    #[Test]
    public function deleteReturns200OnSuccess(): void
    {
        $this->mutator->method('delete')->willReturn(ActionResult::success('Record deleted'));

        $request = new ServerRequest(
            method: 'DELETE',
            uri: '/admin/posts/post-1',
        );

        $response = $this->controller->delete($request, 'posts', 'post-1');

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertTrue($body['success']);
        self::assertSame('Record deleted', $body['message']);
    }

    #[Test]
    public function deleteReturns404OnNotFound(): void
    {
        $this->mutator->method('delete')->willReturn(ActionResult::failure('Record not found'));

        $request = new ServerRequest(
            method: 'DELETE',
            uri: '/admin/posts/post-missing',
        );

        $response = $this->controller->delete($request, 'posts', 'post-missing');

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertFalse($body['success']);
    }

    #[Test]
    public function deleteUsesReasonFromBody(): void
    {
        $mutator = $this->createMock(ResourceMutatorInterface::class);
        $mutator->expects(self::once())
            ->method('delete')
            ->with(
                self::isInstanceOf(DataResourceInterface::class),
                'post-1',
                self::callback(static fn($ctx) => $ctx->reason === 'Spam content'),
            )
            ->willReturn(ActionResult::success('Deleted'));

        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('operations')->willReturn([ResourceOperation::Delete]);

        $registry = $this->createStub(ResourceRegistryInterface::class);
        $registry->method('get')->willReturn($resource);

        $actionHistory = $this->createStub(ActionHistoryStoreInterface::class);
        $handler = new DeleteResourceHandler($registry, $mutator, $actionHistory);
        $controller = new ResourceDeleteController($handler);

        $request = new ServerRequest(
            method: 'DELETE',
            uri: '/admin/posts/post-1',
            parsedBody: ['reason' => 'Spam content'],
        );

        $controller->delete($request, 'posts', 'post-1');
    }

    #[Test]
    public function deleteDefaultsReasonWhenNotProvided(): void
    {
        $mutator = $this->createMock(ResourceMutatorInterface::class);
        $mutator->expects(self::once())
            ->method('delete')
            ->with(
                self::isInstanceOf(DataResourceInterface::class),
                'post-1',
                self::callback(static fn($ctx) => $ctx->reason === 'Admin panel delete'),
            )
            ->willReturn(ActionResult::success('Deleted'));

        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('operations')->willReturn([ResourceOperation::Delete]);

        $registry = $this->createStub(ResourceRegistryInterface::class);
        $registry->method('get')->willReturn($resource);

        $actionHistory = $this->createStub(ActionHistoryStoreInterface::class);
        $handler = new DeleteResourceHandler($registry, $mutator, $actionHistory);
        $controller = new ResourceDeleteController($handler);

        $request = new ServerRequest(
            method: 'DELETE',
            uri: '/admin/posts/post-1',
        );

        $controller->delete($request, 'posts', 'post-1');
    }

    #[Test]
    public function deleteUsesAnonymousActorWhenNoIdentity(): void
    {
        $mutator = $this->createMock(ResourceMutatorInterface::class);
        $mutator->expects(self::once())
            ->method('delete')
            ->with(
                self::isInstanceOf(DataResourceInterface::class),
                'post-1',
                self::callback(static fn($ctx) => $ctx->actor === 'anonymous'),
            )
            ->willReturn(ActionResult::success('Deleted'));

        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('operations')->willReturn([ResourceOperation::Delete]);

        $registry = $this->createStub(ResourceRegistryInterface::class);
        $registry->method('get')->willReturn($resource);

        $actionHistory = $this->createStub(ActionHistoryStoreInterface::class);
        $handler = new DeleteResourceHandler($registry, $mutator, $actionHistory);
        $controller = new ResourceDeleteController($handler);

        $request = new ServerRequest(
            method: 'DELETE',
            uri: '/admin/posts/post-1',
        );

        $controller->delete($request, 'posts', 'post-1');
    }

    #[Test]
    public function deleteIgnoresNonStringReason(): void
    {
        $mutator = $this->createMock(ResourceMutatorInterface::class);
        $mutator->expects(self::once())
            ->method('delete')
            ->with(
                self::isInstanceOf(DataResourceInterface::class),
                'post-1',
                self::callback(static fn($ctx) => $ctx->reason === 'Admin panel delete'),
            )
            ->willReturn(ActionResult::success('Deleted'));

        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('operations')->willReturn([ResourceOperation::Delete]);

        $registry = $this->createStub(ResourceRegistryInterface::class);
        $registry->method('get')->willReturn($resource);

        $actionHistory = $this->createStub(ActionHistoryStoreInterface::class);
        $handler = new DeleteResourceHandler($registry, $mutator, $actionHistory);
        $controller = new ResourceDeleteController($handler);

        $request = new ServerRequest(
            method: 'DELETE',
            uri: '/admin/posts/post-1',
            parsedBody: ['reason' => ['nested']],
        );

        $controller->delete($request, 'posts', 'post-1');
    }
}
