<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Features\UpdateResource;

use PHPUnit\Framework\Attributes\Test;
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
use Pulsar\Extension\Admin\Features\UpdateResource\UpdateResourceResult;
use Pulsar\Extension\Admin\Internal\Storage\ActionHistoryStoreInterface;

final class UpdateResourceHandlerTest extends TestCase
{
    private ResourceRegistryInterface&Stub $registry;
    private ResourceMutatorInterface&Stub $mutator;
    private ActionHistoryStoreInterface&Stub $actionHistory;
    private UpdateResourceHandler $handler;

    protected function setUp(): void
    {
        $this->registry = $this->createStub(ResourceRegistryInterface::class);
        $this->mutator = $this->createStub(ResourceMutatorInterface::class);
        $this->actionHistory = $this->createStub(ActionHistoryStoreInterface::class);
        $this->handler = new UpdateResourceHandler($this->registry, $this->mutator, $this->actionHistory);
    }

    #[Test]
    public function execute_updates_resource_successfully(): void
    {
        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('operations')->willReturn([ResourceOperation::Update]);
        $resource->method('fields')->willReturn([]);

        $this->registry->method('get')->willReturn($resource);

        $actionResult = ActionResult::success('Updated');
        $this->mutator->method('update')->willReturn($actionResult);

        $context = new MutationContext(actor: 'admin@test.com', reason: 'update user');
        $request = new UpdateResourceRequest(
            resourceName: 'users',
            id: 'user-123',
            data: ['name' => 'Updated Name'],
            context: $context,
        );

        $result = $this->handler->execute($request);

        self::assertInstanceOf(UpdateResourceResult::class, $result);
        self::assertTrue($result->result->success);
        self::assertSame('Updated', $result->result->message);
    }

    #[Test]
    public function execute_throws_when_update_not_supported(): void
    {
        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('operations')->willReturn([ResourceOperation::List]);

        $this->registry->method('get')->willReturn($resource);

        $context = new MutationContext(actor: 'admin@test.com', reason: 'test');
        $request = new UpdateResourceRequest(
            resourceName: 'logs',
            id: 'log-1',
            data: ['level' => 'error'],
            context: $context,
        );

        $this->expectException(AdminException::class);
        $this->expectExceptionMessage('Update operation not supported on resource "logs"');

        $this->handler->execute($request);
    }

    #[Test]
    public function execute_validates_only_submitted_fields(): void
    {
        $requiredRule = new ValidationRule(rule: 'required');
        $nameField = new FieldDefinition(
            name: 'name',
            type: FieldType::Text,
            label: 'Name',
            editable: true,
            rules: [$requiredRule],
        );
        $emailField = new FieldDefinition(
            name: 'email',
            type: FieldType::Email,
            label: 'Email',
            editable: true,
            rules: [$requiredRule],
        );

        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('operations')->willReturn([ResourceOperation::Update]);
        $resource->method('fields')->willReturn([$nameField, $emailField]);

        $this->registry->method('get')->willReturn($resource);

        $actionResult = ActionResult::success('Updated');
        $this->mutator->method('update')->willReturn($actionResult);

        $context = new MutationContext(actor: 'admin@test.com', reason: 'partial update');
        $request = new UpdateResourceRequest(
            resourceName: 'users',
            id: 'user-1',
            data: ['name' => 'New Name'],
            context: $context,
        );

        $result = $this->handler->execute($request);

        self::assertTrue($result->result->success);
    }

    #[Test]
    public function execute_throws_validation_exception_for_invalid_submitted_field(): void
    {
        $requiredRule = new ValidationRule(rule: 'required');
        $nameField = new FieldDefinition(
            name: 'name',
            type: FieldType::Text,
            label: 'Name',
            editable: true,
            rules: [$requiredRule],
        );

        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('operations')->willReturn([ResourceOperation::Update]);
        $resource->method('fields')->willReturn([$nameField]);

        $this->registry->method('get')->willReturn($resource);

        $context = new MutationContext(actor: 'admin@test.com', reason: 'test');
        $request = new UpdateResourceRequest(
            resourceName: 'users',
            id: 'user-1',
            data: ['name' => null],
            context: $context,
        );

        $this->expectException(ResourceValidationException::class);

        $this->handler->execute($request);
    }
}
