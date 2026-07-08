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
use Pulsar\Extension\Admin\Features\GlobalSearch\GlobalSearchHandler;
use Pulsar\Extension\Admin\Internal\Policy\FieldVisibilityFilter;
use Pulsar\Extension\Admin\Server\Controller\SearchController;

#[CoversClass(SearchController::class)]
final class SearchControllerTest extends TestCase
{
    #[Test]
    public function searchReturnsJsonForJsonAccept(): void
    {
        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('name')->willReturn('users');
        $resource->method('fields')->willReturn([
            new FieldDefinition(name: 'name', type: FieldType::String, label: 'Name', searchable: true),
        ]);

        $registry = $this->createStub(ResourceRegistryInterface::class);
        $registry->method('all')->willReturn(['users' => $resource]);

        $query = $this->createStub(ResourceQueryInterface::class);
        $query->method('search')->willReturn([['id' => '1', 'name' => 'John']]);

        $handler = new GlobalSearchHandler($registry, $query, new FieldVisibilityFilter());
        $config = AdminConfig::fromArray(['enabled' => true]);

        $controller = new SearchController($handler, $config);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['q' => 'John']);
        $request->method('getHeaderLine')
            ->willReturn('application/json');

        $response = $controller->search($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function searchUsesEmptyStringWhenNoQuery(): void
    {
        $registry = $this->createStub(ResourceRegistryInterface::class);
        $registry->method('all')->willReturn([]);

        $query = $this->createStub(ResourceQueryInterface::class);

        $handler = new GlobalSearchHandler($registry, $query, new FieldVisibilityFilter());
        $config = AdminConfig::fromArray(['enabled' => true]);

        $controller = new SearchController($handler, $config);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([]);
        $request->method('getHeaderLine')
            ->willReturn('application/json');

        $response = $controller->search($request);

        self::assertSame(200, $response->getStatusCode());
    }
}
