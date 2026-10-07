<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Contracts\DataResourceInterface;
use Pulsar\Extension\Admin\Exception\AdminException;
use Pulsar\Extension\Admin\Exception\ResourceNotFoundException;
use Pulsar\Extension\Admin\Internal\Adapter\IntrospectedResource;
use Pulsar\Extension\Admin\Internal\AdminResourceRegistry;
use Pulsar\Extension\Admin\Internal\AdminStudioModule;

#[CoversClass(AdminResourceRegistry::class)]
#[CoversClass(AdminStudioModule::class)]
#[CoversClass(AdminException::class)]
#[CoversClass(ResourceNotFoundException::class)]
#[CoversClass(IntrospectedResource::class)]
final class AdminResourceRegistryTest extends TestCase
{
    // --- AdminResourceRegistry ---

    #[Test]
    public function registerAndGetResource(): void
    {
        $registry = new AdminResourceRegistry();
        $resource = $this->createNamedResource('users');

        $registry->register($resource);

        self::assertSame($resource, $registry->get('users'));
    }

    #[Test]
    public function hasReturnsTrueForRegistered(): void
    {
        $registry = new AdminResourceRegistry();
        $registry->register($this->createNamedResource('orders'));

        self::assertTrue($registry->has('orders'));
        self::assertFalse($registry->has('products'));
    }

    #[Test]
    public function allReturnsAllRegistered(): void
    {
        $registry = new AdminResourceRegistry();
        $registry->register($this->createNamedResource('users'));
        $registry->register($this->createNamedResource('orders'));

        $all = $registry->all();

        self::assertCount(2, $all);
        self::assertArrayHasKey('users', $all);
        self::assertArrayHasKey('orders', $all);
    }

    #[Test]
    public function allReturnsEmptyWhenNoResources(): void
    {
        $registry = new AdminResourceRegistry();

        self::assertSame([], $registry->all());
    }

    #[Test]
    public function getThrowsForUnknownResource(): void
    {
        $registry = new AdminResourceRegistry();

        $this->expectException(ResourceNotFoundException::class);
        $this->expectExceptionMessageIsOrContains('"nonexistent" not found');

        $registry->get('nonexistent');
    }

    #[Test]
    public function registerDuplicateThrows(): void
    {
        $registry = new AdminResourceRegistry();
        $registry->register($this->createNamedResource('users'));

        $this->expectException(AdminException::class);
        $this->expectExceptionMessageIsOrContains('already registered');

        $registry->register($this->createNamedResource('users'));
    }

    #[Test]
    public function typedResourceReplacesIntrospectedResource(): void
    {
        $registry = new AdminResourceRegistry();

        // Register a real IntrospectedResource (final class — cannot be stubbed)
        $introspected = new IntrospectedResource(
            tableName: 'users',
            singularLabel: 'User',
            pluralLabel: 'Users',
            primaryKeyField: 'id',
            fields: [],
        );

        $registry->register($introspected);

        // Register a typed (non-introspected) resource with the same name — should replace
        $typed = $this->createNamedResource('users');
        $registry->register($typed);

        self::assertSame($typed, $registry->get('users'));
    }

    // --- AdminException ---

    #[Test]
    public function adminExceptionDisabled(): void
    {
        $exception = AdminException::disabled();

        self::assertStringContainsString('disabled', $exception->getMessage());
        self::assertInstanceOf(AdminException::class, $exception);
    }

    #[Test]
    public function adminExceptionInvalidConfiguration(): void
    {
        $exception = AdminException::invalidConfiguration('missing key');

        self::assertStringContainsString('missing key', $exception->getMessage());
    }

    #[Test]
    public function adminExceptionResourceAlreadyRegistered(): void
    {
        $exception = AdminException::resourceAlreadyRegistered('users');

        self::assertStringContainsString('"users"', $exception->getMessage());
    }

    // --- ResourceNotFoundException ---

    #[Test]
    public function resourceNotFoundExceptionResource(): void
    {
        $exception = ResourceNotFoundException::resource('posts');

        self::assertStringContainsString('"posts" not found', $exception->getMessage());
        self::assertInstanceOf(AdminException::class, $exception);
    }

    #[Test]
    public function resourceNotFoundExceptionRecord(): void
    {
        $exception = ResourceNotFoundException::record('users', '42');

        self::assertStringContainsString('"42"', $exception->getMessage());
        self::assertStringContainsString('"users"', $exception->getMessage());
    }

    // --- AdminStudioModule ---

    #[Test]
    public function studioModuleId(): void
    {
        $module = new AdminStudioModule();

        self::assertSame('admin', $module->moduleId());
    }

    #[Test]
    public function studioModuleLabel(): void
    {
        $module = new AdminStudioModule();

        self::assertSame('Admin Panel', $module->label());
    }

    #[Test]
    public function studioModuleIcon(): void
    {
        $module = new AdminStudioModule();

        self::assertSame('shield', $module->icon());
    }

    #[Test]
    public function studioModuleNavEntries(): void
    {
        $module = new AdminStudioModule();
        $entries = $module->navEntries();

        self::assertCount(3, $entries);
        self::assertSame('Dashboard', $entries[0]->label);
        self::assertSame('/admin', $entries[0]->href);
        self::assertSame('Resources', $entries[1]->label);
        self::assertSame('Activity log', $entries[2]->label);
    }

    #[Test]
    public function studioModuleRoutePrefix(): void
    {
        $module = new AdminStudioModule();

        self::assertSame('/studio/admin', $module->routePrefix());
    }

    #[Test]
    public function studioModuleNavOrder(): void
    {
        $module = new AdminStudioModule();

        self::assertSame(50, $module->navOrder());
    }

    // --- IntrospectedResource ---

    #[Test]
    public function introspectedResourceNameIsTableName(): void
    {
        $resource = $this->buildIntrospectedResource();

        self::assertSame('products', $resource->name());
        self::assertSame('products', $resource->tableName());
    }

    #[Test]
    public function introspectedResourceLabels(): void
    {
        $resource = $this->buildIntrospectedResource();

        self::assertSame('Product', $resource->label());
        self::assertSame('Products', $resource->pluralLabel());
    }

    #[Test]
    public function introspectedResourceIconIsTable(): void
    {
        $resource = $this->buildIntrospectedResource();

        self::assertSame('table', $resource->icon());
    }

    #[Test]
    public function introspectedResourceOperations(): void
    {
        $resource = $this->buildIntrospectedResource();
        $ops = $resource->operations();

        self::assertCount(6, $ops);
    }

    #[Test]
    public function introspectedResourceBulkActions(): void
    {
        $resource = $this->buildIntrospectedResource();
        $actions = $resource->bulkActions();

        self::assertCount(1, $actions);
        self::assertSame('delete', $actions[0]->name);
        self::assertTrue($actions[0]->destructive);
    }

    #[Test]
    public function introspectedResourcePrimaryKeyAndSort(): void
    {
        $resource = $this->buildIntrospectedResource();

        self::assertSame('id', $resource->primaryKey());
        self::assertSame('id', $resource->defaultSortField());
        self::assertSame('ASC', $resource->defaultSortDirection());
    }

    #[Test]
    public function introspectedResourceDoesNotAuditReads(): void
    {
        $resource = $this->buildIntrospectedResource();

        self::assertFalse($resource->auditReads());
    }

    private function buildIntrospectedResource(): IntrospectedResource
    {
        return new IntrospectedResource(
            tableName: 'products',
            singularLabel: 'Product',
            pluralLabel: 'Products',
            primaryKeyField: 'id',
            fields: [],
        );
    }

    private function createNamedResource(string $name): DataResourceInterface
    {
        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('name')->willReturn($name);

        return $resource;
    }
}
