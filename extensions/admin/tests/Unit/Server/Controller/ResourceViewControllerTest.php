<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Server\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Extension\Admin\Contracts\DataResourceInterface;
use Pulsar\Extension\Admin\Contracts\ResourceQueryInterface;
use Pulsar\Extension\Admin\Contracts\ResourceRegistryInterface;
use Pulsar\Extension\Admin\Domain\FieldDefinition;
use Pulsar\Extension\Admin\Domain\FieldType;
use Pulsar\Extension\Admin\Features\ViewResource\ViewResourceHandler;
use Pulsar\Extension\Admin\Internal\Policy\FieldVisibilityFilter;
use Pulsar\Extension\Admin\Server\Controller\ResourceViewController;

#[CoversClass(ResourceViewController::class)]
final class ResourceViewControllerTest extends TestCase
{
    #[Test]
    public function viewReturnsJsonWhenAcceptJson(): void
    {
        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('name')->willReturn('users');
        $resource->method('fields')->willReturn([
            new FieldDefinition(name: 'id', type: FieldType::Integer, label: 'ID'),
        ]);

        $registry = $this->createStub(ResourceRegistryInterface::class);
        $registry->method('get')->willReturn($resource);

        $query = $this->createStub(ResourceQueryInterface::class);
        $query->method('find')->willReturn(['id' => 1, 'name' => 'John']);

        $handler = new ViewResourceHandler($registry, $query, new FieldVisibilityFilter());
        $config = AdminConfig::fromArray(['enabled' => true]);
        $controller = new ResourceViewController($handler, $registry, $config);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')
            ->willReturn('application/json');

        $response = $controller->view($request, 'users', '1');

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function viewReturns404WhenRecordNotFound(): void
    {
        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('name')->willReturn('users');
        $resource->method('fields')->willReturn([]);

        $registry = $this->createStub(ResourceRegistryInterface::class);
        $registry->method('get')->willReturn($resource);

        $query = $this->createStub(ResourceQueryInterface::class);
        $query->method('find')->willReturn(null);

        $handler = new ViewResourceHandler($registry, $query, new FieldVisibilityFilter());
        $config = AdminConfig::fromArray(['enabled' => true]);
        $controller = new ResourceViewController($handler, $registry, $config);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')
            ->willReturn('application/json');

        $response = $controller->view($request, 'users', '999');

        self::assertSame(404, $response->getStatusCode());
    }
}
