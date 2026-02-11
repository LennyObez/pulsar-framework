<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Admin\Server\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Extension\Admin\Contracts\DataResourceInterface;
use Pulsar\Extension\Admin\Contracts\ResourceQueryInterface;
use Pulsar\Extension\Admin\Contracts\ResourceRegistryInterface;
use Pulsar\Extension\Admin\Domain\FieldDefinition;
use Pulsar\Extension\Admin\Domain\FieldType;
use Pulsar\Extension\Admin\Features\ListResource\ListResourceHandler;
use Pulsar\Extension\Admin\Internal\Policy\FieldVisibilityFilter;
use Pulsar\Extension\Admin\Server\Controller\ResourceListController;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;

#[CoversClass(ResourceListController::class)]
final class ResourceListControllerTest extends TestCase
{
    private function makeController(
        ResourceRegistryInterface $registry,
        ResourceQueryInterface $query,
    ): ResourceListController {
        $config = AdminConfig::fromArray(['enabled' => true]);
        $filter = new FieldVisibilityFilter();
        $handler = new ListResourceHandler($registry, $query, $filter, $config);

        return new ResourceListController($handler, $registry, $config);
    }

    private function makeResource(): DataResourceInterface
    {
        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('name')->willReturn('users');
        $resource->method('label')->willReturn('User');
        $resource->method('pluralLabel')->willReturn('Users');
        $resource->method('primaryKey')->willReturn('id');
        $resource->method('defaultSortField')->willReturn('id');
        $resource->method('defaultSortDirection')->willReturn('desc');
        $resource->method('fields')->willReturn([
            new FieldDefinition('id', FieldType::Integer, 'ID', sortable: true),
            new FieldDefinition('name', FieldType::String, 'Name', sortable: true),
        ]);

        return $resource;
    }

    #[Test]
    public function listReturnsJsonResponseWhenAcceptHeader(): void
    {
        $resource = $this->makeResource();

        $registry = $this->createStub(ResourceRegistryInterface::class);
        $registry->method('get')->willReturn($resource);

        $query = $this->createStub(ResourceQueryInterface::class);
        $query->method('list')->willReturn([
            'data' => [['id' => 1, 'name' => 'John']],
            'total' => 50,
            'page' => 1,
            'per_page' => 25,
        ]);

        $controller = $this->makeController($registry, $query);

        $request = new Request(
            method: Method::GET,
            uri: '/admin/resources/users',
            path: '/admin/resources/users',
            queryString: '',
            headers: new HeaderBag(['Accept' => 'application/json']),
            body: '',
        );

        $response = $controller->list($request, 'users');

        self::assertSame(200, $response->status->value);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        self::assertSame(50, $body['total']);
        self::assertSame(1, $body['page']);
        self::assertSame(25, $body['per_page']);
        self::assertSame(2, $body['total_pages']);
        /** @var list<mixed> $data */
        $data = $body['data'];
        self::assertCount(1, $data);
    }

    #[Test]
    public function listParsesSortParameters(): void
    {
        $resource = $this->makeResource();

        $registry = $this->createStub(ResourceRegistryInterface::class);
        $registry->method('get')->willReturn($resource);

        $query = $this->createStub(ResourceQueryInterface::class);
        $query->method('list')->willReturn([
            'data' => [],
            'total' => 0,
            'page' => 1,
            'per_page' => 25,
        ]);

        $controller = $this->makeController($registry, $query);

        $request = new Request(
            method: Method::GET,
            uri: '/admin/resources/users',
            path: '/admin/resources/users',
            queryString: 'sort_field=name&sort_dir=asc',
            headers: new HeaderBag(['Accept' => 'application/json']),
            body: '',
            query: ['sort_field' => 'name', 'sort_dir' => 'asc'],
        );

        $response = $controller->list($request, 'users');

        self::assertSame(200, $response->status->value);
    }

    #[Test]
    public function listUsesDefaultPagination(): void
    {
        $resource = $this->makeResource();

        $registry = $this->createStub(ResourceRegistryInterface::class);
        $registry->method('get')->willReturn($resource);

        $query = $this->createStub(ResourceQueryInterface::class);
        $query->method('list')->willReturn([
            'data' => [],
            'total' => 0,
            'page' => 1,
            'per_page' => 25,
        ]);

        $controller = $this->makeController($registry, $query);

        $request = new Request(
            method: Method::GET,
            uri: '/admin/resources/users',
            path: '/admin/resources/users',
            queryString: '',
            headers: new HeaderBag(['Accept' => 'application/json']),
            body: '',
        );

        $response = $controller->list($request, 'users');

        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        self::assertSame(1, $body['page']);
        self::assertSame(25, $body['per_page']);
    }

    #[Test]
    public function listWithCustomPagination(): void
    {
        $resource = $this->makeResource();

        $registry = $this->createStub(ResourceRegistryInterface::class);
        $registry->method('get')->willReturn($resource);

        $query = $this->createStub(ResourceQueryInterface::class);
        $query->method('list')->willReturn([
            'data' => [],
            'total' => 100,
            'page' => 3,
            'per_page' => 10,
        ]);

        $controller = $this->makeController($registry, $query);

        $request = new Request(
            method: Method::GET,
            uri: '/admin/resources/users',
            path: '/admin/resources/users',
            queryString: 'page=3&per_page=10',
            headers: new HeaderBag(['Accept' => 'application/json']),
            body: '',
            query: ['page' => '3', 'per_page' => '10'],
        );

        $response = $controller->list($request, 'users');

        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        self::assertSame(3, $body['page']);
        self::assertSame(10, $body['per_page']);
    }

    #[Test]
    public function listWithEmptyResult(): void
    {
        $resource = $this->makeResource();

        $registry = $this->createStub(ResourceRegistryInterface::class);
        $registry->method('get')->willReturn($resource);

        $query = $this->createStub(ResourceQueryInterface::class);
        $query->method('list')->willReturn([
            'data' => [],
            'total' => 0,
            'page' => 1,
            'per_page' => 25,
        ]);

        $controller = $this->makeController($registry, $query);

        $request = new Request(
            method: Method::GET,
            uri: '/admin/resources/users',
            path: '/admin/resources/users',
            queryString: '',
            headers: new HeaderBag(['Accept' => 'application/json']),
            body: '',
        );

        $response = $controller->list($request, 'users');

        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        self::assertSame(0, $body['total']);
        self::assertSame([], $body['data']);
        self::assertSame(1, $body['total_pages']);
    }
}
