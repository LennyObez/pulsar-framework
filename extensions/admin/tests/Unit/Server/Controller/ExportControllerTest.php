<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Server\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Admin\Contracts\DataResourceInterface;
use Pulsar\Extension\Admin\Contracts\ResourceQueryInterface;
use Pulsar\Extension\Admin\Contracts\ResourceRegistryInterface;
use Pulsar\Extension\Admin\Domain\FieldDefinition;
use Pulsar\Extension\Admin\Domain\FieldType;
use Pulsar\Extension\Admin\Domain\ResourceOperation;
use Pulsar\Extension\Admin\Features\ExportResource\ExportResourceHandler;
use Pulsar\Extension\Admin\Internal\Policy\FieldVisibilityFilter;
use Pulsar\Extension\Admin\Server\Controller\ExportController;

#[CoversClass(ExportController::class)]
final class ExportControllerTest extends TestCase
{
    #[Test]
    public function exportReturnsCsvResponse(): void
    {
        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('name')->willReturn('users');
        $resource->method('fields')->willReturn([
            new FieldDefinition(name: 'id', type: FieldType::Integer, label: 'ID'),
            new FieldDefinition(name: 'name', type: FieldType::String, label: 'Name'),
        ]);
        $resource->method('exportableFields')->willReturn(['id', 'name']);
        $resource->method('operations')->willReturn([ResourceOperation::Export]);

        $registry = $this->createStub(ResourceRegistryInterface::class);
        $registry->method('get')->willReturn($resource);

        $query = $this->createStub(ResourceQueryInterface::class);
        $query->method('list')->willReturn([
            'data' => [['id' => 1, 'name' => 'John']],
            'total' => 1,
            'page' => 1,
            'per_page' => 10000,
        ]);

        $auditLogger = $this->createStub(AuditLoggerInterface::class);
        $filter = new FieldVisibilityFilter();

        $handler = new ExportResourceHandler($registry, $query, $filter, $auditLogger);
        $controller = new ExportController($handler);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['format' => 'csv']);

        $response = $controller->export($request, 'users');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/csv', $response->getHeaderLine('Content-Type'));
        self::assertNotEmpty($response->getHeaderLine('X-Evidence-Hash'));
    }

    #[Test]
    public function exportDefaultsToCsvWhenNoFormat(): void
    {
        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('name')->willReturn('users');
        $resource->method('fields')->willReturn([
            new FieldDefinition(name: 'id', type: FieldType::Integer, label: 'ID'),
        ]);
        $resource->method('exportableFields')->willReturn(['id']);
        $resource->method('operations')->willReturn([ResourceOperation::Export]);

        $registry = $this->createStub(ResourceRegistryInterface::class);
        $registry->method('get')->willReturn($resource);

        $query = $this->createStub(ResourceQueryInterface::class);
        $query->method('list')->willReturn([
            'data' => [['id' => 1]],
            'total' => 1,
            'page' => 1,
            'per_page' => 10000,
        ]);

        $auditLogger = $this->createStub(AuditLoggerInterface::class);
        $filter = new FieldVisibilityFilter();

        $handler = new ExportResourceHandler($registry, $query, $filter, $auditLogger);
        $controller = new ExportController($handler);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([]);

        $response = $controller->export($request, 'users');

        self::assertStringContainsString('text/csv', $response->getHeaderLine('Content-Type'));
    }
}
