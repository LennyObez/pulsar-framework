<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Features\DeleteResource;

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
use Pulsar\Extension\Admin\Features\DeleteResource\DeleteResourceHandler;
use Pulsar\Extension\Admin\Features\DeleteResource\DeleteResourceRequest;
use Pulsar\Extension\Admin\Features\DeleteResource\DeleteResourceResult;
use Pulsar\Extension\Admin\Internal\Storage\ActionHistoryStoreInterface;

final class DeleteResourceHandlerTest extends TestCase
{
    private ResourceRegistryInterface&Stub $registry;
    private ResourceMutatorInterface&Stub $mutator;
    private ActionHistoryStoreInterface&Stub $actionHistory;
    private DeleteResourceHandler $handler;

    protected function setUp(): void
    {
        $this->registry = $this->createStub(ResourceRegistryInterface::class);
        $this->mutator = $this->createStub(ResourceMutatorInterface::class);
        $this->actionHistory = $this->createStub(ActionHistoryStoreInterface::class);
        $this->handler = new DeleteResourceHandler($this->registry, $this->mutator, $this->actionHistory);
    }

    #[Test]
    public function execute_deletes_resource_successfully(): void
    {
        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('operations')->willReturn([ResourceOperation::Delete]);

        $this->registry->method('get')->willReturn($resource);

        $actionResult = ActionResult::success('Deleted');
        $this->mutator->method('delete')->willReturn($actionResult);

        $context = new MutationContext(actor: 'admin@test.com', reason: 'remove user');
        $request = new DeleteResourceRequest(
            resourceName: 'users',
            id: 'user-456',
            context: $context,
        );

        $result = $this->handler->execute($request);

        self::assertInstanceOf(DeleteResourceResult::class, $result);
        self::assertTrue($result->result->success);
        self::assertSame('Deleted', $result->result->message);
    }

    #[Test]
    public function execute_throws_when_delete_not_supported(): void
    {
        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('operations')->willReturn([ResourceOperation::List, ResourceOperation::View]);

        $this->registry->method('get')->willReturn($resource);

        $context = new MutationContext(actor: 'admin@test.com', reason: 'test');
        $request = new DeleteResourceRequest(
            resourceName: 'audit_logs',
            id: 'log-1',
            context: $context,
        );

        $this->expectException(AdminException::class);
        $this->expectExceptionMessage('Delete operation not supported on resource "audit_logs"');

        $this->handler->execute($request);
    }

    #[Test]
    public function execute_records_failed_deletion_in_history(): void
    {
        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('operations')->willReturn([ResourceOperation::Delete]);

        $this->registry->method('get')->willReturn($resource);

        $failureResult = ActionResult::failure('Record not found');
        $this->mutator->method('delete')->willReturn($failureResult);

        $context = new MutationContext(actor: 'admin@test.com', reason: 'cleanup');
        $request = new DeleteResourceRequest(
            resourceName: 'users',
            id: 'missing-id',
            context: $context,
        );

        $result = $this->handler->execute($request);

        self::assertFalse($result->result->success);
        self::assertSame('Record not found', $result->result->message);
    }
}
