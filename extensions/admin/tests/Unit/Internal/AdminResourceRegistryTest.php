<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Internal;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Contracts\DataResourceInterface;
use Pulsar\Extension\Admin\Exception\AdminException;
use Pulsar\Extension\Admin\Exception\ResourceNotFoundException;
use Pulsar\Extension\Admin\Internal\Adapter\IntrospectedResource;
use Pulsar\Extension\Admin\Internal\AdminResourceRegistry;

final class AdminResourceRegistryTest extends TestCase
{
    private AdminResourceRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new AdminResourceRegistry();
    }

    #[Test]
    public function register_and_get(): void
    {
        $resource = $this->createResourceStub('users');
        $this->registry->register($resource);

        self::assertSame($resource, $this->registry->get('users'));
    }

    #[Test]
    public function has_returns_false_when_not_registered(): void
    {
        self::assertFalse($this->registry->has('nonexistent'));
    }

    #[Test]
    public function has_returns_true_when_registered(): void
    {
        $this->registry->register($this->createResourceStub('users'));

        self::assertTrue($this->registry->has('users'));
    }

    #[Test]
    public function all_returns_all_resources(): void
    {
        $this->registry->register($this->createResourceStub('users'));
        $this->registry->register($this->createResourceStub('orders'));

        $all = $this->registry->all();

        self::assertCount(2, $all);
        self::assertArrayHasKey('users', $all);
        self::assertArrayHasKey('orders', $all);
    }

    #[Test]
    public function all_returns_empty_when_no_resources(): void
    {
        self::assertSame([], $this->registry->all());
    }

    #[Test]
    public function get_throws_when_not_found(): void
    {
        $this->expectException(ResourceNotFoundException::class);
        $this->expectExceptionMessage('Resource "missing" not found');

        $this->registry->get('missing');
    }

    #[Test]
    public function duplicate_registration_throws(): void
    {
        $this->registry->register($this->createResourceStub('users'));

        $this->expectException(AdminException::class);
        $this->expectExceptionMessage('Resource "users" is already registered');

        $this->registry->register($this->createResourceStub('users'));
    }

    #[Test]
    public function typed_resource_replaces_introspected_resource(): void
    {
        // Register a real IntrospectedResource (final class, cannot be mocked)
        $introspected = new IntrospectedResource(
            tableName: 'users',
            singularLabel: 'User',
            pluralLabel: 'Users',
            primaryKeyField: 'id',
            fields: [],
        );
        $this->registry->register($introspected);

        // Register a typed resource with the same name: should replace
        $typed = $this->createResourceStub('users');
        $this->registry->register($typed);

        self::assertSame($typed, $this->registry->get('users'));
    }

    private function createResourceStub(string $name): DataResourceInterface&Stub
    {
        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('name')->willReturn($name);

        return $resource;
    }
}
