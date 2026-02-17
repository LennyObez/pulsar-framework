<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Features\ExportResource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Admin\Contracts\DataResourceInterface;
use Pulsar\Extension\Admin\Contracts\ResourceQueryInterface;
use Pulsar\Extension\Admin\Contracts\ResourceRegistryInterface;
use Pulsar\Extension\Admin\Domain\ExportFormat;
use Pulsar\Extension\Admin\Domain\FieldDefinition;
use Pulsar\Extension\Admin\Domain\FieldType;
use Pulsar\Extension\Admin\Domain\ResourceOperation;
use Pulsar\Extension\Admin\Exception\AdminException;
use Pulsar\Extension\Admin\Features\ExportResource\ExportResourceHandler;
use Pulsar\Extension\Admin\Features\ExportResource\ExportResourceRequest;
use Pulsar\Extension\Admin\Features\ExportResource\ExportResourceResult;
use Pulsar\Extension\Admin\Internal\Policy\FieldVisibilityFilter;

final class ExportResourceHandlerTest extends TestCase
{
    private ResourceRegistryInterface&Stub $registry;
    private ResourceQueryInterface&Stub $query;
    private FieldVisibilityFilter $visibilityFilter;
    private AuditLoggerInterface&Stub $auditLogger;
    private ExportResourceHandler $handler;

    protected function setUp(): void
    {
        $this->registry = $this->createStub(ResourceRegistryInterface::class);
        $this->query = $this->createStub(ResourceQueryInterface::class);
        $this->visibilityFilter = new FieldVisibilityFilter();
        $this->auditLogger = $this->createStub(AuditLoggerInterface::class);
        $this->handler = new ExportResourceHandler(
            $this->registry,
            $this->query,
            $this->visibilityFilter,
            $this->auditLogger,
        );
    }

    #[Test]
    public function execute_exports_csv_successfully(): void
    {
        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('operations')->willReturn([ResourceOperation::Export]);
        $resource->method('exportableFields')->willReturn(['name', 'email']);
        $resource->method('fields')->willReturn([
            new FieldDefinition(name: 'name', type: FieldType::Text, label: 'Name', exportable: true),
            new FieldDefinition(name: 'email', type: FieldType::Email, label: 'Email', exportable: true),
        ]);

        $this->registry->method('get')->willReturn($resource);

        $this->query->method('list')->willReturn([
            'data' => [['name' => 'Alice', 'email' => 'alice@test.com']],
            'total' => 1,
            'page' => 1,
            'per_page' => 10000,
        ]);

        $request = new ExportResourceRequest(
            resourceName: 'users',
            format: ExportFormat::Csv,
        );

        $result = $this->handler->execute($request);

        self::assertInstanceOf(ExportResourceResult::class, $result);
        self::assertSame(1, $result->rowCount);
        self::assertStringContainsString('.csv', $result->filename);
        self::assertNotEmpty($result->evidenceHash);
        self::assertNotEmpty($result->content);
    }

    #[Test]
    public function execute_exports_json_successfully(): void
    {
        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('operations')->willReturn([ResourceOperation::Export]);
        $resource->method('exportableFields')->willReturn(['id', 'title']);
        $resource->method('fields')->willReturn([
            new FieldDefinition(name: 'id', type: FieldType::Text, label: 'ID', exportable: true),
            new FieldDefinition(name: 'title', type: FieldType::Text, label: 'Title', exportable: true),
        ]);

        $this->registry->method('get')->willReturn($resource);

        $this->query->method('list')->willReturn([
            'data' => [['id' => '1', 'title' => 'Test']],
            'total' => 1,
            'page' => 1,
            'per_page' => 10000,
        ]);

        $request = new ExportResourceRequest(
            resourceName: 'posts',
            format: ExportFormat::Json,
        );

        $result = $this->handler->execute($request);

        self::assertStringContainsString('.json', $result->filename);
        self::assertStringStartsWith('application/json', $result->mimeType);
    }

    #[Test]
    public function execute_throws_when_export_not_supported(): void
    {
        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('operations')->willReturn([ResourceOperation::List]);

        $this->registry->method('get')->willReturn($resource);

        $request = new ExportResourceRequest(
            resourceName: 'logs',
            format: ExportFormat::Csv,
        );

        $this->expectException(AdminException::class);
        $this->expectExceptionMessage('Export not supported on resource "logs"');

        $this->handler->execute($request);
    }

    #[Test]
    public function execute_throws_when_no_exportable_fields(): void
    {
        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('operations')->willReturn([ResourceOperation::Export]);
        $resource->method('exportableFields')->willReturn([]);

        $this->registry->method('get')->willReturn($resource);

        $request = new ExportResourceRequest(
            resourceName: 'users',
            format: ExportFormat::Csv,
        );

        $this->expectException(AdminException::class);
        $this->expectExceptionMessage('No exportable fields');

        $this->handler->execute($request);
    }
}
