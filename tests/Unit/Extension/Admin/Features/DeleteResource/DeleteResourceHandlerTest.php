<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Admin\Features\DeleteResource;

use function assert;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\MutationContext;
use Pulsar\Extension\Admin\Contracts\DataResourceInterface;
use Pulsar\Extension\Admin\Contracts\ResourceMutatorInterface;
use Pulsar\Extension\Admin\Contracts\ResourceRegistryInterface;
use Pulsar\Extension\Admin\Domain\ActionResult;
use Pulsar\Extension\Admin\Domain\ResourceOperation;
use Pulsar\Extension\Admin\Exception\AdminException;
use Pulsar\Extension\Admin\Features\DeleteResource\DeleteResourceHandler;
use Pulsar\Extension\Admin\Features\DeleteResource\DeleteResourceRequest;
use Pulsar\Extension\Admin\Internal\Storage\ActionHistoryEntry;
use Pulsar\Extension\Admin\Internal\Storage\ActionHistoryStoreInterface;

#[CoversClass(DeleteResourceHandler::class)]
final class DeleteResourceHandlerTest extends TestCase
{
    private ResourceRegistryInterface&Stub $registry;
    private ResourceMutatorInterface&Stub $mutator;
    private ActionHistoryStoreInterface&Stub $actionHistory;
    private DataResourceInterface&Stub $resource;
    private DeleteResourceHandler $handler;

    protected function setUp(): void
    {
        $this->registry = $this->createStub(ResourceRegistryInterface::class);
        $this->mutator = $this->createStub(ResourceMutatorInterface::class);
        $this->actionHistory = $this->createStub(ActionHistoryStoreInterface::class);
        $this->resource = $this->createStub(DataResourceInterface::class);

        $this->registry->method('get')->willReturn($this->resource);

        $this->handler = new DeleteResourceHandler(
            $this->registry,
            $this->mutator,
            $this->actionHistory,
        );
    }

    private function handlerWithMocks(
        ActionHistoryStoreInterface|null $actionHistory = null,
        ResourceMutatorInterface|null $mutator = null,
    ): DeleteResourceHandler {
        return new DeleteResourceHandler(
            $this->registry,
            $mutator ?? $this->mutator,
            $actionHistory ?? $this->actionHistory,
        );
    }

    #[Test]
    public function deletesResourceSuccessfully(): void
    {
        $this->resource->method('operations')->willReturn([ResourceOperation::Delete]);

        $actionResult = ActionResult::success('Deleted');
        $this->mutator->method('delete')->willReturn($actionResult);

        /** @var ActionHistoryStoreInterface&MockObject $actionHistory */
        $actionHistory = $this->createMock(ActionHistoryStoreInterface::class);
        $actionHistory->expects($this->once())->method('record');

        $handler = $this->handlerWithMocks(actionHistory: $actionHistory);

        $request = new DeleteResourceRequest(
            resourceName: 'users',
            id: '42',
            context: new MutationContext('admin', 'Test delete'),
        );

        $result = $handler->execute($request);

        self::assertTrue($result->result->success);
        self::assertSame('Deleted', $result->result->message);
    }

    #[Test]
    public function throwsWhenDeleteNotSupported(): void
    {
        $this->resource->method('operations')->willReturn([ResourceOperation::List, ResourceOperation::View]);

        $request = new DeleteResourceRequest(
            resourceName: 'audit_logs',
            id: '1',
            context: new MutationContext('admin', 'Test delete'),
        );

        $this->expectException(AdminException::class);
        $this->expectExceptionMessage('Delete operation not supported');

        $this->handler->execute($request);
    }

    #[Test]
    public function recordsActionHistoryOnDelete(): void
    {
        $this->resource->method('operations')->willReturn([ResourceOperation::Delete]);

        $actionResult = ActionResult::success('Deleted');
        $this->mutator->method('delete')->willReturn($actionResult);

        /** @var ActionHistoryStoreInterface&MockObject $actionHistory */
        $actionHistory = $this->createMock(ActionHistoryStoreInterface::class);
        $actionHistory->expects($this->once())
            ->method('record')
            ->with($this->callback(static function (mixed $entry): bool {
                assert($entry instanceof ActionHistoryEntry);

                return $entry->action === 'delete'
                    && $entry->resourceName === 'users'
                    && $entry->recordId === '42'
                    && $entry->actor === 'admin'
                    && $entry->success === true;
            }));

        $handler = $this->handlerWithMocks(actionHistory: $actionHistory);

        $request = new DeleteResourceRequest(
            resourceName: 'users',
            id: '42',
            context: new MutationContext('admin', 'Test delete'),
        );

        $handler->execute($request);
    }

    #[Test]
    public function recordsFailedDeleteInHistory(): void
    {
        $this->resource->method('operations')->willReturn([ResourceOperation::Delete]);

        $actionResult = ActionResult::failure('Not found');
        $this->mutator->method('delete')->willReturn($actionResult);

        /** @var ActionHistoryStoreInterface&MockObject $actionHistory */
        $actionHistory = $this->createMock(ActionHistoryStoreInterface::class);
        $actionHistory->expects($this->once())
            ->method('record')
            ->with($this->callback(static function (mixed $entry): bool {
                assert($entry instanceof ActionHistoryEntry);

                return $entry->action === 'delete'
                    && $entry->success === false
                    && $entry->detail === 'Not found';
            }));

        $handler = $this->handlerWithMocks(actionHistory: $actionHistory);

        $request = new DeleteResourceRequest(
            resourceName: 'users',
            id: '999',
            context: new MutationContext('admin', 'Test delete'),
        );

        $result = $handler->execute($request);

        self::assertFalse($result->result->success);
    }

    #[Test]
    public function passesContextToMutator(): void
    {
        $this->resource->method('operations')->willReturn([ResourceOperation::Delete]);

        $context = new MutationContext('superadmin', 'Removing spam user');

        /** @var ResourceMutatorInterface&MockObject $mutator */
        $mutator = $this->createMock(ResourceMutatorInterface::class);
        $mutator->expects($this->once())
            ->method('delete')
            ->with($this->resource, '7', $context)
            ->willReturn(ActionResult::success('Deleted'));

        $handler = $this->handlerWithMocks(mutator: $mutator);

        $request = new DeleteResourceRequest(
            resourceName: 'users',
            id: '7',
            context: $context,
        );

        $handler->execute($request);
    }
}
