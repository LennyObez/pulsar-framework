<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Features\BulkAction;

use PHPUnit\Framework\Attributes\Test;
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
use Pulsar\Extension\Admin\Features\BulkAction\BulkActionResult;
use Pulsar\Extension\Admin\Internal\Storage\ActionHistoryStoreInterface;

final class BulkActionHandlerTest extends TestCase
{
    private ResourceRegistryInterface&Stub $registry;
    private ResourceMutatorInterface&Stub $mutator;
    private ActionHistoryStoreInterface&Stub $actionHistory;
    private BulkActionHandler $handler;

    protected function setUp(): void
    {
        $this->registry = $this->createStub(ResourceRegistryInterface::class);
        $this->mutator = $this->createStub(ResourceMutatorInterface::class);
        $this->actionHistory = $this->createStub(ActionHistoryStoreInterface::class);
        $this->handler = new BulkActionHandler($this->registry, $this->mutator, $this->actionHistory);
    }

    #[Test]
    public function execute_successful_bulk_action(): void
    {
        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('operations')->willReturn([ResourceOperation::BulkAction]);

        $this->registry->method('get')->willReturn($resource);

        $actionResult = ActionResult::success('3 records archived');
        $this->mutator->method('bulkAction')->willReturn($actionResult);

        $context = new MutationContext(actor: 'admin@test.com', reason: 'bulk archive');
        $request = new BulkActionRequest(
            resourceName: 'users',
            action: 'archive',
            ids: ['id-1', 'id-2', 'id-3'],
            parameters: ['notify' => true],
            context: $context,
        );

        $result = $this->handler->execute($request);

        self::assertInstanceOf(BulkActionResult::class, $result);
        self::assertTrue($result->result->success);
        self::assertSame('3 records archived', $result->result->message);
    }

    #[Test]
    public function execute_throws_when_bulk_action_not_supported(): void
    {
        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('operations')->willReturn([ResourceOperation::List, ResourceOperation::View]);

        $this->registry->method('get')->willReturn($resource);

        $context = new MutationContext(actor: 'admin@test.com', reason: 'test');
        $request = new BulkActionRequest(
            resourceName: 'products',
            action: 'delete',
            ids: ['id-1'],
            parameters: [],
            context: $context,
        );

        $this->expectException(AdminException::class);
        $this->expectExceptionMessage('Bulk actions not supported on resource "products"');

        $this->handler->execute($request);
    }

    #[Test]
    public function execute_records_failed_bulk_action_in_history(): void
    {
        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('operations')->willReturn([ResourceOperation::BulkAction]);

        $this->registry->method('get')->willReturn($resource);

        $failureResult = ActionResult::failure('Permission denied');
        $this->mutator->method('bulkAction')->willReturn($failureResult);

        $context = new MutationContext(actor: 'admin@test.com', reason: 'cleanup');
        $request = new BulkActionRequest(
            resourceName: 'orders',
            action: 'cancel',
            ids: ['ord-1'],
            parameters: [],
            context: $context,
        );

        $result = $this->handler->execute($request);

        self::assertFalse($result->result->success);
        self::assertSame('Permission denied', $result->result->message);
    }
}
