<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Admin\Features\BulkAction;

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
use Pulsar\Extension\Admin\Features\BulkAction\BulkActionHandler;
use Pulsar\Extension\Admin\Features\BulkAction\BulkActionRequest;
use Pulsar\Extension\Admin\Internal\Storage\ActionHistoryEntry;
use Pulsar\Extension\Admin\Internal\Storage\ActionHistoryStoreInterface;

#[CoversClass(BulkActionHandler::class)]
final class BulkActionHandlerTest extends TestCase
{
    private ResourceRegistryInterface&Stub $registry;
    private ResourceMutatorInterface&Stub $mutator;
    private ActionHistoryStoreInterface&Stub $actionHistory;
    private DataResourceInterface&Stub $resource;
    private BulkActionHandler $handler;

    protected function setUp(): void
    {
        $this->registry = $this->createStub(ResourceRegistryInterface::class);
        $this->mutator = $this->createStub(ResourceMutatorInterface::class);
        $this->actionHistory = $this->createStub(ActionHistoryStoreInterface::class);
        $this->resource = $this->createStub(DataResourceInterface::class);

        $this->registry->method('get')->willReturn($this->resource);

        $this->handler = new BulkActionHandler(
            $this->registry,
            $this->mutator,
            $this->actionHistory,
        );
    }

    private function handlerWithMocks(
        ActionHistoryStoreInterface|null $actionHistory = null,
        ResourceMutatorInterface|null $mutator = null,
    ): BulkActionHandler {
        return new BulkActionHandler(
            $this->registry,
            $mutator ?? $this->mutator,
            $actionHistory ?? $this->actionHistory,
        );
    }

    #[Test]
    public function executesBulkActionSuccessfully(): void
    {
        $this->resource->method('operations')->willReturn([ResourceOperation::BulkAction]);

        $actionResult = ActionResult::success('3 records deleted');
        $this->mutator->method('bulkAction')->willReturn($actionResult);

        /** @var ActionHistoryStoreInterface&MockObject $actionHistory */
        $actionHistory = $this->createMock(ActionHistoryStoreInterface::class);
        $actionHistory->expects($this->once())->method('record');

        $handler = $this->handlerWithMocks(actionHistory: $actionHistory);

        $request = new BulkActionRequest(
            resourceName: 'users',
            action: 'delete',
            ids: ['1', '2', '3'],
            parameters: [],
            context: new MutationContext('admin', 'Bulk delete'),
        );

        $result = $handler->execute($request);

        self::assertTrue($result->result->success);
        self::assertSame('3 records deleted', $result->result->message);
    }

    #[Test]
    public function throwsWhenBulkActionNotSupported(): void
    {
        $this->resource->method('operations')->willReturn([ResourceOperation::List, ResourceOperation::View]);

        $request = new BulkActionRequest(
            resourceName: 'audit_logs',
            action: 'delete',
            ids: ['1'],
            parameters: [],
            context: new MutationContext('admin', 'Test'),
        );

        $this->expectException(AdminException::class);
        $this->expectExceptionMessage('Bulk actions not supported');

        $this->handler->execute($request);
    }

    #[Test]
    public function passesParametersToMutator(): void
    {
        $this->resource->method('operations')->willReturn([ResourceOperation::BulkAction]);

        $params = ['status' => 'archived'];
        $ids = ['10', '20'];
        $context = new MutationContext('admin', 'Archiving');

        /** @var ResourceMutatorInterface&MockObject $mutator */
        $mutator = $this->createMock(ResourceMutatorInterface::class);
        $mutator->expects($this->once())
            ->method('bulkAction')
            ->with($this->resource, 'archive', $ids, $params, $context)
            ->willReturn(ActionResult::success('Archived'));

        $handler = $this->handlerWithMocks(mutator: $mutator);

        $request = new BulkActionRequest(
            resourceName: 'users',
            action: 'archive',
            ids: $ids,
            parameters: $params,
            context: $context,
        );

        $handler->execute($request);
    }

    #[Test]
    public function recordsActionHistoryWithBulkPrefix(): void
    {
        $this->resource->method('operations')->willReturn([ResourceOperation::BulkAction]);

        $actionResult = ActionResult::success('Done');
        $this->mutator->method('bulkAction')->willReturn($actionResult);

        /** @var ActionHistoryStoreInterface&MockObject $actionHistory */
        $actionHistory = $this->createMock(ActionHistoryStoreInterface::class);
        $actionHistory->expects($this->once())
            ->method('record')
            ->with($this->callback(static function (mixed $entry): bool {
                assert($entry instanceof ActionHistoryEntry);

                return $entry->action === 'bulk.deactivate'
                    && $entry->resourceName === 'users'
                    && $entry->recordId === null
                    && $entry->actor === 'admin'
                    && $entry->success === true;
            }));

        $handler = $this->handlerWithMocks(actionHistory: $actionHistory);

        $request = new BulkActionRequest(
            resourceName: 'users',
            action: 'deactivate',
            ids: ['1', '2'],
            parameters: [],
            context: new MutationContext('admin', 'Deactivate users'),
        );

        $handler->execute($request);
    }

    #[Test]
    public function recordsFailedBulkActionInHistory(): void
    {
        $this->resource->method('operations')->willReturn([ResourceOperation::BulkAction]);

        $actionResult = ActionResult::failure('Permission denied');
        $this->mutator->method('bulkAction')->willReturn($actionResult);

        /** @var ActionHistoryStoreInterface&MockObject $actionHistory */
        $actionHistory = $this->createMock(ActionHistoryStoreInterface::class);
        $actionHistory->expects($this->once())
            ->method('record')
            ->with($this->callback(static function (mixed $entry): bool {
                assert($entry instanceof ActionHistoryEntry);

                return $entry->success === false
                    && $entry->detail === 'Permission denied';
            }));

        $handler = $this->handlerWithMocks(actionHistory: $actionHistory);

        $request = new BulkActionRequest(
            resourceName: 'users',
            action: 'delete',
            ids: ['1'],
            parameters: [],
            context: new MutationContext('admin', 'Test'),
        );

        $result = $handler->execute($request);

        self::assertFalse($result->result->success);
    }
}
