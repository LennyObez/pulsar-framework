<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Admin\Features\UpdateResource;

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
use Pulsar\Extension\Admin\Domain\FieldDefinition;
use Pulsar\Extension\Admin\Domain\FieldType;
use Pulsar\Extension\Admin\Domain\ResourceOperation;
use Pulsar\Extension\Admin\Domain\ValidationRule;
use Pulsar\Extension\Admin\Exception\AdminException;
use Pulsar\Extension\Admin\Exception\ResourceValidationException;
use Pulsar\Extension\Admin\Features\UpdateResource\UpdateResourceHandler;
use Pulsar\Extension\Admin\Features\UpdateResource\UpdateResourceRequest;
use Pulsar\Extension\Admin\Internal\Storage\ActionHistoryEntry;
use Pulsar\Extension\Admin\Internal\Storage\ActionHistoryStoreInterface;

#[CoversClass(UpdateResourceHandler::class)]
final class UpdateResourceHandlerTest extends TestCase
{
    private ResourceRegistryInterface&Stub $registry;
    private ResourceMutatorInterface&Stub $mutator;
    private ActionHistoryStoreInterface&Stub $actionHistory;
    private DataResourceInterface&Stub $resource;
    private UpdateResourceHandler $handler;

    protected function setUp(): void
    {
        $this->registry = $this->createStub(ResourceRegistryInterface::class);
        $this->mutator = $this->createStub(ResourceMutatorInterface::class);
        $this->actionHistory = $this->createStub(ActionHistoryStoreInterface::class);
        $this->resource = $this->createStub(DataResourceInterface::class);

        $this->registry->method('get')->willReturn($this->resource);

        $this->handler = new UpdateResourceHandler(
            $this->registry,
            $this->mutator,
            $this->actionHistory,
        );
    }

    private function handlerWithMocks(
        ActionHistoryStoreInterface|null $actionHistory = null,
    ): UpdateResourceHandler {
        return new UpdateResourceHandler(
            $this->registry,
            $this->mutator,
            $actionHistory ?? $this->actionHistory,
        );
    }

    #[Test]
    public function updatesResourceSuccessfully(): void
    {
        $this->resource->method('operations')->willReturn([ResourceOperation::Update]);
        $this->resource->method('fields')->willReturn([]);

        $actionResult = ActionResult::success('Updated');
        $this->mutator->method('update')->willReturn($actionResult);

        /** @var ActionHistoryStoreInterface&MockObject $actionHistory */
        $actionHistory = $this->createMock(ActionHistoryStoreInterface::class);
        $actionHistory->expects($this->once())->method('record');

        $handler = $this->handlerWithMocks(actionHistory: $actionHistory);

        $request = new UpdateResourceRequest(
            resourceName: 'users',
            id: '42',
            data: ['name' => 'Updated Name'],
            context: new MutationContext('admin', 'Test update'),
        );

        $result = $handler->execute($request);

        self::assertTrue($result->result->success);
        self::assertSame('Updated', $result->result->message);
    }

    #[Test]
    public function throwsWhenUpdateNotSupported(): void
    {
        $this->resource->method('operations')->willReturn([ResourceOperation::List, ResourceOperation::View]);

        $request = new UpdateResourceRequest(
            resourceName: 'audit_logs',
            id: '1',
            data: ['name' => 'test'],
            context: new MutationContext('admin', 'Test update'),
        );

        $this->expectException(AdminException::class);
        $this->expectExceptionMessage('Update operation not supported');

        $this->handler->execute($request);
    }

    #[Test]
    public function throwsOnValidationFailure(): void
    {
        $this->resource->method('operations')->willReturn([ResourceOperation::Update]);
        $this->resource->method('fields')->willReturn([
            new FieldDefinition(
                name: 'email',
                type: FieldType::Email,
                label: 'Email',
                editable: true,
                rules: [new ValidationRule('email')],
            ),
        ]);

        $request = new UpdateResourceRequest(
            resourceName: 'users',
            id: '1',
            data: ['email' => 'not-an-email'],
            context: new MutationContext('admin', 'Test update'),
        );

        $this->expectException(ResourceValidationException::class);

        $this->handler->execute($request);
    }

    #[Test]
    public function skipsValidationForFieldsNotInData(): void
    {
        $this->resource->method('operations')->willReturn([ResourceOperation::Update]);
        $this->resource->method('fields')->willReturn([
            new FieldDefinition(
                name: 'email',
                type: FieldType::Email,
                label: 'Email',
                editable: true,
                rules: [new ValidationRule('required')],
            ),
            new FieldDefinition(
                name: 'name',
                type: FieldType::String,
                label: 'Name',
                editable: true,
                rules: [new ValidationRule('required')],
            ),
        ]);

        $actionResult = ActionResult::success('Updated');
        $this->mutator->method('update')->willReturn($actionResult);

        /** @var ActionHistoryStoreInterface&MockObject $actionHistory */
        $actionHistory = $this->createMock(ActionHistoryStoreInterface::class);
        $actionHistory->expects($this->once())->method('record');

        $handler = $this->handlerWithMocks(actionHistory: $actionHistory);

        // Only update name, should not validate email
        $request = new UpdateResourceRequest(
            resourceName: 'users',
            id: '1',
            data: ['name' => 'New Name'],
            context: new MutationContext('admin', 'Partial update'),
        );

        $result = $handler->execute($request);

        self::assertTrue($result->result->success);
    }

    #[Test]
    public function recordsActionHistoryOnUpdate(): void
    {
        $this->resource->method('operations')->willReturn([ResourceOperation::Update]);
        $this->resource->method('fields')->willReturn([]);

        $actionResult = ActionResult::success('Updated');
        $this->mutator->method('update')->willReturn($actionResult);

        /** @var ActionHistoryStoreInterface&MockObject $actionHistory */
        $actionHistory = $this->createMock(ActionHistoryStoreInterface::class);
        $actionHistory->expects($this->once())
            ->method('record')
            ->with($this->callback(static function (mixed $entry): bool {
                assert($entry instanceof ActionHistoryEntry);

                return $entry->action === 'update'
                    && $entry->resourceName === 'users'
                    && $entry->recordId === '42'
                    && $entry->actor === 'admin'
                    && $entry->success === true;
            }));

        $handler = $this->handlerWithMocks(actionHistory: $actionHistory);

        $request = new UpdateResourceRequest(
            resourceName: 'users',
            id: '42',
            data: ['name' => 'Updated'],
            context: new MutationContext('admin', 'Test'),
        );

        $handler->execute($request);
    }

    #[Test]
    public function skipsValidationForNonEditableFields(): void
    {
        $this->resource->method('operations')->willReturn([ResourceOperation::Update]);
        $this->resource->method('fields')->willReturn([
            new FieldDefinition(
                name: 'id',
                type: FieldType::Integer,
                label: 'ID',
                editable: false,
                rules: [new ValidationRule('required')],
            ),
        ]);

        $actionResult = ActionResult::success('Updated');
        $this->mutator->method('update')->willReturn($actionResult);

        /** @var ActionHistoryStoreInterface&MockObject $actionHistory */
        $actionHistory = $this->createMock(ActionHistoryStoreInterface::class);
        $actionHistory->expects($this->once())->method('record');

        $handler = $this->handlerWithMocks(actionHistory: $actionHistory);

        $request = new UpdateResourceRequest(
            resourceName: 'users',
            id: '1',
            data: ['id' => ''],
            context: new MutationContext('admin', 'Test'),
        );

        $result = $handler->execute($request);

        self::assertTrue($result->result->success);
    }
}
