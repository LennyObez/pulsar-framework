<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Admin\Features\CreateResource;

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
use Pulsar\Extension\Admin\Features\CreateResource\CreateResourceHandler;
use Pulsar\Extension\Admin\Features\CreateResource\CreateResourceRequest;
use Pulsar\Extension\Admin\Internal\Storage\ActionHistoryEntry;
use Pulsar\Extension\Admin\Internal\Storage\ActionHistoryStoreInterface;

use function assert;

#[CoversClass(CreateResourceHandler::class)]
final class CreateResourceHandlerTest extends TestCase
{
    private ResourceRegistryInterface&Stub $registry;
    private ResourceMutatorInterface&Stub $mutator;
    private ActionHistoryStoreInterface&Stub $actionHistory;
    private DataResourceInterface&Stub $resource;
    private CreateResourceHandler $handler;

    protected function setUp(): void
    {
        $this->registry = $this->createStub(ResourceRegistryInterface::class);
        $this->mutator = $this->createStub(ResourceMutatorInterface::class);
        $this->actionHistory = $this->createStub(ActionHistoryStoreInterface::class);
        $this->resource = $this->createStub(DataResourceInterface::class);

        $this->registry->method('get')->willReturn($this->resource);

        $this->handler = new CreateResourceHandler(
            $this->registry,
            $this->mutator,
            $this->actionHistory,
        );
    }

    private function handlerWithMocks(
        ActionHistoryStoreInterface|null $actionHistory = null,
    ): CreateResourceHandler {
        return new CreateResourceHandler(
            $this->registry,
            $this->mutator,
            $actionHistory ?? $this->actionHistory,
        );
    }

    #[Test]
    public function createsResourceSuccessfully(): void
    {
        $this->resource->method('operations')->willReturn([ResourceOperation::Create]);
        $this->resource->method('fields')->willReturn([]);

        $actionResult = ActionResult::success('Created', ['id' => '42']);
        $this->mutator->method('create')->willReturn($actionResult);

        /** @var ActionHistoryStoreInterface&MockObject $actionHistory */
        $actionHistory = $this->createMock(ActionHistoryStoreInterface::class);
        $actionHistory->expects($this->once())->method('record');

        $handler = $this->handlerWithMocks(actionHistory: $actionHistory);

        $request = new CreateResourceRequest(
            resourceName: 'users',
            data: ['name' => 'Alice'],
            context: new MutationContext('admin', 'Test create'),
        );

        $result = $handler->execute($request);

        self::assertTrue($result->result->success);
        self::assertSame('Created', $result->result->message);
    }

    #[Test]
    public function throwsWhenCreateNotSupported(): void
    {
        $this->resource->method('operations')->willReturn([ResourceOperation::List, ResourceOperation::View]);

        $request = new CreateResourceRequest(
            resourceName: 'audit_logs',
            data: ['name' => 'test'],
            context: new MutationContext('admin', 'Test create'),
        );

        $this->expectException(AdminException::class);
        $this->expectExceptionMessage('Create operation not supported');

        $this->handler->execute($request);
    }

    #[Test]
    public function throwsOnValidationFailure(): void
    {
        $this->resource->method('operations')->willReturn([ResourceOperation::Create]);
        $this->resource->method('fields')->willReturn([
            new FieldDefinition(
                name: 'email',
                type: FieldType::Email,
                label: 'Email',
                editable: true,
                rules: [new ValidationRule('required'), new ValidationRule('email')],
            ),
        ]);

        $request = new CreateResourceRequest(
            resourceName: 'users',
            data: ['email' => ''],
            context: new MutationContext('admin', 'Test create'),
        );

        $this->expectException(ResourceValidationException::class);

        $this->handler->execute($request);
    }

    #[Test]
    public function skipsValidationForNonEditableFields(): void
    {
        $this->resource->method('operations')->willReturn([ResourceOperation::Create]);
        $this->resource->method('fields')->willReturn([
            new FieldDefinition(
                name: 'id',
                type: FieldType::Integer,
                label: 'ID',
                editable: false,
                rules: [new ValidationRule('required')],
            ),
            new FieldDefinition(
                name: 'name',
                type: FieldType::String,
                label: 'Name',
                editable: true,
                rules: [],
            ),
        ]);

        $actionResult = ActionResult::success('Created', ['id' => '1']);
        $this->mutator->method('create')->willReturn($actionResult);

        /** @var ActionHistoryStoreInterface&MockObject $actionHistory */
        $actionHistory = $this->createMock(ActionHistoryStoreInterface::class);
        $actionHistory->expects($this->once())->method('record');

        $handler = $this->handlerWithMocks(actionHistory: $actionHistory);

        $request = new CreateResourceRequest(
            resourceName: 'users',
            data: ['name' => 'Alice'],
            context: new MutationContext('admin', 'Test create'),
        );

        $result = $handler->execute($request);

        self::assertTrue($result->result->success);
    }

    #[Test]
    public function recordsActionHistoryOnCreate(): void
    {
        $this->resource->method('operations')->willReturn([ResourceOperation::Create]);
        $this->resource->method('fields')->willReturn([]);

        $actionResult = ActionResult::success('Created', ['id' => '99']);
        $this->mutator->method('create')->willReturn($actionResult);

        /** @var ActionHistoryStoreInterface&MockObject $actionHistory */
        $actionHistory = $this->createMock(ActionHistoryStoreInterface::class);
        $actionHistory->expects($this->once())
            ->method('record')
            ->with($this->callback(static function (mixed $entry): bool {
                assert($entry instanceof ActionHistoryEntry);

                return $entry->action === 'create'
                    && $entry->resourceName === 'users'
                    && $entry->actor === 'admin'
                    && $entry->success === true
                    && $entry->recordId === '99';
            }));

        $handler = $this->handlerWithMocks(actionHistory: $actionHistory);

        $request = new CreateResourceRequest(
            resourceName: 'users',
            data: ['name' => 'Bob'],
            context: new MutationContext('admin', 'Test create'),
        );

        $handler->execute($request);
    }

    #[Test]
    public function validatesMultipleRulesOnSingleField(): void
    {
        $this->resource->method('operations')->willReturn([ResourceOperation::Create]);
        $this->resource->method('fields')->willReturn([
            new FieldDefinition(
                name: 'username',
                type: FieldType::String,
                label: 'Username',
                editable: true,
                rules: [
                    new ValidationRule('required'),
                    new ValidationRule('min_length', parameter: 3),
                ],
            ),
        ]);

        $request = new CreateResourceRequest(
            resourceName: 'users',
            data: ['username' => ''],
            context: new MutationContext('admin', 'Test'),
        );

        try {
            $this->handler->execute($request);
            self::fail('Expected ResourceValidationException');
        } catch (ResourceValidationException $e) {
            self::assertNotEmpty($e->violations);
            self::assertSame('username', $e->violations[0]['field']);
        }
    }
}
