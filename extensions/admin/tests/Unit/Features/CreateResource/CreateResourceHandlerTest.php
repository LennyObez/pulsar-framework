<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Features\CreateResource;

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
use Pulsar\Extension\Admin\Features\CreateResource\CreateResourceHandler;
use Pulsar\Extension\Admin\Features\CreateResource\CreateResourceRequest;
use Pulsar\Extension\Admin\Features\CreateResource\CreateResourceResult;
use Pulsar\Extension\Admin\Internal\Storage\ActionHistoryStoreInterface;

final class CreateResourceHandlerTest extends TestCase
{
    private ResourceRegistryInterface&Stub $registry;
    private ResourceMutatorInterface&Stub $mutator;
    private ActionHistoryStoreInterface&Stub $actionHistory;
    private CreateResourceHandler $handler;

    protected function setUp(): void
    {
        $this->registry = $this->createStub(ResourceRegistryInterface::class);
        $this->mutator = $this->createStub(ResourceMutatorInterface::class);
        $this->actionHistory = $this->createStub(ActionHistoryStoreInterface::class);
        $this->handler = new CreateResourceHandler($this->registry, $this->mutator, $this->actionHistory);
    }

    #[Test]
    public function execute_creates_resource_successfully(): void
    {
        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('operations')->willReturn([ResourceOperation::Create]);
        $resource->method('fields')->willReturn([]);

        $this->registry->method('get')->willReturn($resource);

        $actionResult = ActionResult::success('Created', ['id' => 'new-123']);
        $this->mutator->method('create')->willReturn($actionResult);

        $context = new MutationContext(actor: 'admin@test.com', reason: 'create user');
        $request = new CreateResourceRequest(
            resourceName: 'users',
            data: ['name' => 'Jane Doe', 'email' => 'jane@example.com'],
            context: $context,
        );

        $result = $this->handler->execute($request);

        self::assertInstanceOf(CreateResourceResult::class, $result);
        self::assertTrue($result->result->success);
        self::assertSame('Created', $result->result->message);
    }

    #[Test]
    public function execute_throws_when_create_not_supported(): void
    {
        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('operations')->willReturn([ResourceOperation::List, ResourceOperation::View]);

        $this->registry->method('get')->willReturn($resource);

        $context = new MutationContext(actor: 'admin@test.com', reason: 'test');
        $request = new CreateResourceRequest(
            resourceName: 'audit_logs',
            data: ['entry' => 'test'],
            context: $context,
        );

        $this->expectException(AdminException::class);
        $this->expectExceptionMessage('Create operation not supported on resource "audit_logs"');

        $this->handler->execute($request);
    }

    #[Test]
    public function execute_throws_validation_exception_for_required_field(): void
    {
        $requiredRule = new ValidationRule(rule: 'required');
        $field = new FieldDefinition(
            name: 'email',
            type: FieldType::Email,
            label: 'Email',
            editable: true,
            rules: [$requiredRule],
        );

        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('operations')->willReturn([ResourceOperation::Create]);
        $resource->method('fields')->willReturn([$field]);

        $this->registry->method('get')->willReturn($resource);

        $context = new MutationContext(actor: 'admin@test.com', reason: 'test');
        $request = new CreateResourceRequest(
            resourceName: 'users',
            data: [],
            context: $context,
        );

        $this->expectException(ResourceValidationException::class);

        $this->handler->execute($request);
    }

    #[Test]
    public function execute_skips_validation_on_non_editable_fields(): void
    {
        $requiredRule = new ValidationRule(rule: 'required');
        $readOnlyField = new FieldDefinition(
            name: 'id',
            type: FieldType::Text,
            label: 'ID',
            editable: false,
            rules: [$requiredRule],
        );

        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('operations')->willReturn([ResourceOperation::Create]);
        $resource->method('fields')->willReturn([$readOnlyField]);

        $this->registry->method('get')->willReturn($resource);

        $actionResult = ActionResult::success('Created');
        $this->mutator->method('create')->willReturn($actionResult);

        $context = new MutationContext(actor: 'admin@test.com', reason: 'test');
        $request = new CreateResourceRequest(
            resourceName: 'users',
            data: [],
            context: $context,
        );

        $result = $this->handler->execute($request);

        self::assertTrue($result->result->success);
    }
}
