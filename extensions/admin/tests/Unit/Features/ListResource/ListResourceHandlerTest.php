<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Features\ListResource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Extension\Admin\Config\AdminPaginationConfig;
use Pulsar\Extension\Admin\Config\AdminRateLimitConfig;
use Pulsar\Extension\Admin\Config\AdminSchemaConfig;
use Pulsar\Extension\Admin\Config\AdminSecurityConfig;
use Pulsar\Extension\Admin\Config\AdminStorageConfig;
use Pulsar\Extension\Admin\Contracts\DataResourceInterface;
use Pulsar\Extension\Admin\Contracts\ResourceQueryInterface;
use Pulsar\Extension\Admin\Contracts\ResourceRegistryInterface;
use Pulsar\Extension\Admin\Domain\FieldDefinition;
use Pulsar\Extension\Admin\Domain\FieldType;
use Pulsar\Extension\Admin\Domain\ListResourceResult;
use Pulsar\Extension\Admin\Features\ListResource\ListResourceHandler;
use Pulsar\Extension\Admin\Features\ListResource\ListResourceRequest;
use Pulsar\Extension\Admin\Internal\Policy\FieldVisibilityFilter;

final class ListResourceHandlerTest extends TestCase
{
    private ResourceRegistryInterface&Stub $registry;
    private ResourceQueryInterface&Stub $query;
    private FieldVisibilityFilter $visibilityFilter;
    private AdminConfig $config;
    private ListResourceHandler $handler;

    protected function setUp(): void
    {
        $this->registry = $this->createStub(ResourceRegistryInterface::class);
        $this->query = $this->createStub(ResourceQueryInterface::class);
        $this->visibilityFilter = new FieldVisibilityFilter();
        $this->config = new AdminConfig(
            enabled: true,
            routePrefix: '/admin',
            security: AdminSecurityConfig::fromArray([]),
            pagination: new AdminPaginationConfig(defaultPerPage: 25, maxPerPage: 100),
            rateLimit: AdminRateLimitConfig::fromArray([]),
            storage: AdminStorageConfig::fromArray([]),
            schema: AdminSchemaConfig::fromArray([]),
        );
        $this->handler = new ListResourceHandler(
            $this->registry,
            $this->query,
            $this->visibilityFilter,
            $this->config,
        );
    }

    #[Test]
    public function execute_returns_paginated_results(): void
    {
        $resource = $this->createResourceStub();
        $this->registry->method('get')->willReturn($resource);

        $this->query->method('list')->willReturn([
            'data' => [['id' => '1', 'name' => 'Alice'], ['id' => '2', 'name' => 'Bob']],
            'total' => 50,
            'page' => 1,
            'per_page' => 25,
        ]);

        $request = new ListResourceRequest(resourceName: 'users', page: 1, perPage: 25);
        $result = $this->handler->execute($request);

        self::assertInstanceOf(ListResourceResult::class, $result);
        self::assertCount(2, $result->data);
        self::assertSame(50, $result->total);
        self::assertSame(2, $result->totalPages);
    }

    #[Test]
    public function execute_clamps_per_page_to_max(): void
    {
        $resource = $this->createResourceStub();
        $this->registry->method('get')->willReturn($resource);

        $this->query->method('list')->willReturn([
            'data' => [],
            'total' => 0,
            'page' => 1,
            'per_page' => 100,
        ]);

        $request = new ListResourceRequest(resourceName: 'users', page: 1, perPage: 999);
        $result = $this->handler->execute($request);

        self::assertSame(1, $result->totalPages);
    }

    #[Test]
    public function execute_clamps_per_page_minimum_to_one(): void
    {
        $resource = $this->createResourceStub();
        $this->registry->method('get')->willReturn($resource);

        $this->query->method('list')->willReturn([
            'data' => [['id' => '1']],
            'total' => 1,
            'page' => 1,
            'per_page' => 1,
        ]);

        $request = new ListResourceRequest(resourceName: 'users', page: 1, perPage: 0);
        $result = $this->handler->execute($request);

        self::assertSame(1, $result->perPage);
    }

    #[Test]
    public function execute_returns_one_total_page_when_empty(): void
    {
        $resource = $this->createResourceStub();
        $this->registry->method('get')->willReturn($resource);

        $this->query->method('list')->willReturn([
            'data' => [],
            'total' => 0,
            'page' => 1,
            'per_page' => 25,
        ]);

        $request = new ListResourceRequest(resourceName: 'users');
        $result = $this->handler->execute($request);

        self::assertSame(0, $result->total);
        self::assertSame(1, $result->totalPages);
    }

    private function createResourceStub(): DataResourceInterface&Stub
    {
        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('primaryKey')->willReturn('id');
        $resource->method('fields')->willReturn([
            new FieldDefinition(name: 'id', type: FieldType::Text, label: 'ID'),
            new FieldDefinition(name: 'name', type: FieldType::Text, label: 'Name'),
        ]);

        return $resource;
    }
}
