<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Features\ViewResource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Contracts\DataResourceInterface;
use Pulsar\Extension\Admin\Contracts\ResourceQueryInterface;
use Pulsar\Extension\Admin\Contracts\ResourceRegistryInterface;
use Pulsar\Extension\Admin\Domain\FieldDefinition;
use Pulsar\Extension\Admin\Domain\FieldType;
use Pulsar\Extension\Admin\Exception\ResourceNotFoundException;
use Pulsar\Extension\Admin\Features\ViewResource\ViewResourceHandler;
use Pulsar\Extension\Admin\Features\ViewResource\ViewResourceRequest;
use Pulsar\Extension\Admin\Features\ViewResource\ViewResourceResult;
use Pulsar\Extension\Admin\Internal\Policy\FieldVisibilityFilter;

final class ViewResourceHandlerTest extends TestCase
{
    private ResourceRegistryInterface&Stub $registry;
    private ResourceQueryInterface&Stub $query;
    private FieldVisibilityFilter $visibilityFilter;
    private ViewResourceHandler $handler;

    protected function setUp(): void
    {
        $this->registry = $this->createStub(ResourceRegistryInterface::class);
        $this->query = $this->createStub(ResourceQueryInterface::class);
        $this->visibilityFilter = new FieldVisibilityFilter();
        $this->handler = new ViewResourceHandler($this->registry, $this->query, $this->visibilityFilter);
    }

    #[Test]
    public function execute_returns_filtered_record(): void
    {
        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('primaryKey')->willReturn('id');
        $resource->method('fields')->willReturn([
            new FieldDefinition(name: 'id', type: FieldType::Text, label: 'ID'),
            new FieldDefinition(name: 'name', type: FieldType::Text, label: 'Name'),
        ]);
        $this->registry->method('get')->willReturn($resource);

        $record = ['id' => 'usr-1', 'name' => 'Jane'];
        $this->query->method('find')->willReturn($record);

        $request = new ViewResourceRequest(resourceName: 'users', id: 'usr-1');
        $result = $this->handler->execute($request);

        self::assertInstanceOf(ViewResourceResult::class, $result);
        self::assertSame('usr-1', $result->data['id']);
        self::assertSame('Jane', $result->data['name']);
    }

    #[Test]
    public function execute_throws_not_found_when_record_missing(): void
    {
        $resource = $this->createStub(DataResourceInterface::class);
        $this->registry->method('get')->willReturn($resource);
        $this->query->method('find')->willReturn(null);

        $request = new ViewResourceRequest(resourceName: 'users', id: 'missing-id');

        $this->expectException(ResourceNotFoundException::class);

        $this->handler->execute($request);
    }
}
