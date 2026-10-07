<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Features\ViewResource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
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

#[CoversClass(ViewResourceHandler::class)]
#[CoversClass(ViewResourceRequest::class)]
#[CoversClass(ViewResourceResult::class)]
final class ViewResourceHandlerTest extends TestCase
{
    #[Test]
    public function executesSuccessfully(): void
    {
        $resource = $this->createResourceStub();

        $registry = $this->createStub(ResourceRegistryInterface::class);
        $registry->method('get')->willReturn($resource);

        $query = $this->createStub(ResourceQueryInterface::class);
        $query->method('find')->willReturn(['id' => '1', 'name' => 'Test User']);

        $filter = new FieldVisibilityFilter();
        $handler = new ViewResourceHandler($registry, $query, $filter);

        $request = new ViewResourceRequest(resourceName: 'users', id: '1');
        $result = $handler->execute($request);

        self::assertArrayHasKey('name', $result->data);
        self::assertSame('Test User', $result->data['name']);
    }

    #[Test]
    public function throwsWhenRecordNotFound(): void
    {
        $resource = $this->createResourceStub();

        $registry = $this->createStub(ResourceRegistryInterface::class);
        $registry->method('get')->willReturn($resource);

        $query = $this->createStub(ResourceQueryInterface::class);
        $query->method('find')->willReturn(null);

        $filter = new FieldVisibilityFilter();
        $handler = new ViewResourceHandler($registry, $query, $filter);

        $this->expectException(ResourceNotFoundException::class);
        $this->expectExceptionMessageMatches('/not found/');

        $handler->execute(new ViewResourceRequest(resourceName: 'users', id: '999'));
    }

    #[Test]
    public function requestDtoStoresProperties(): void
    {
        $request = new ViewResourceRequest(resourceName: 'orders', id: '42');

        self::assertSame('orders', $request->resourceName);
        self::assertSame('42', $request->id);
    }

    #[Test]
    public function resultDtoStoresData(): void
    {
        $result = new ViewResourceResult(data: ['key' => 'value']);

        self::assertSame(['key' => 'value'], $result->data);
    }

    private function createResourceStub(): DataResourceInterface
    {
        $field = new FieldDefinition(
            name: 'name',
            type: FieldType::Text,
            label: 'Name',
            visibleOnDetail: true,
        );

        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('name')->willReturn('users');
        $resource->method('fields')->willReturn([$field]);
        $resource->method('primaryKey')->willReturn('id');

        return $resource;
    }
}
