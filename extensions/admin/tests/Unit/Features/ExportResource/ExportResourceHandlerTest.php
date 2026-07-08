<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Features\ExportResource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
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
use Pulsar\Extension\Admin\Internal\Policy\FieldVisibilityFilter;
use Pulsar\Security\Audit\AuditEntry;

#[CoversClass(ExportResourceHandler::class)]
final class ExportResourceHandlerTest extends TestCase
{
    private ResourceRegistryInterface&Stub $registry;
    private ResourceQueryInterface&Stub $query;
    private AuditLoggerInterface&Stub $auditLogger;
    private DataResourceInterface&Stub $resource;
    private ExportResourceHandler $handler;

    protected function setUp(): void
    {
        $this->registry = $this->createStub(ResourceRegistryInterface::class);
        $this->query = $this->createStub(ResourceQueryInterface::class);
        $this->auditLogger = $this->createStub(AuditLoggerInterface::class);
        $this->resource = $this->createStub(DataResourceInterface::class);

        $this->registry->method('get')->willReturn($this->resource);

        // AuditLogger::log returns an AuditEntry; stub it to avoid errors
        $this->auditLogger->method('log')->willReturn(
            $this->createStub(AuditEntry::class),
        );

        $this->handler = new ExportResourceHandler(
            $this->registry,
            $this->query,
            new FieldVisibilityFilter(),
            $this->auditLogger,
        );
    }

    private function handlerWithMocks(
        ResourceQueryInterface|null $query = null,
        AuditLoggerInterface|null $auditLogger = null,
    ): ExportResourceHandler {
        return new ExportResourceHandler(
            $this->registry,
            $query ?? $this->query,
            new FieldVisibilityFilter(),
            $auditLogger ?? $this->auditLogger,
        );
    }

    #[Test]
    public function exportsCsvSuccessfully(): void
    {
        $this->resource->method('operations')->willReturn([ResourceOperation::Export]);
        $this->resource->method('exportableFields')->willReturn(['id', 'name']);
        $this->resource->method('fields')->willReturn([
            new FieldDefinition(name: 'id', type: FieldType::Integer, label: 'ID', exportable: true),
            new FieldDefinition(name: 'name', type: FieldType::String, label: 'Name', exportable: true),
        ]);

        $this->query->method('list')->willReturn([
            'data' => [
                ['id' => 1, 'name' => 'Alice'],
                ['id' => 2, 'name' => 'Bob'],
            ],
            'total' => 2,
            'page' => 1,
            'per_page' => 10000,
        ]);

        $request = new ExportResourceRequest(
            resourceName: 'users',
            format: ExportFormat::Csv,
        );

        $result = $this->handler->execute($request);

        self::assertStringContainsString('text/csv', $result->mimeType);
        self::assertStringContainsString('users_export_', $result->filename);
        self::assertStringEndsWith('.csv', $result->filename);
        self::assertSame(2, $result->rowCount);
        self::assertNotEmpty($result->evidenceHash);
        self::assertNotEmpty($result->content);
    }

    #[Test]
    public function exportsJsonSuccessfully(): void
    {
        $this->resource->method('operations')->willReturn([ResourceOperation::Export]);
        $this->resource->method('exportableFields')->willReturn(['id', 'name']);
        $this->resource->method('fields')->willReturn([
            new FieldDefinition(name: 'id', type: FieldType::Integer, label: 'ID', exportable: true),
            new FieldDefinition(name: 'name', type: FieldType::String, label: 'Name', exportable: true),
        ]);

        $this->query->method('list')->willReturn([
            'data' => [
                ['id' => 1, 'name' => 'Alice'],
            ],
            'total' => 1,
            'page' => 1,
            'per_page' => 10000,
        ]);

        $request = new ExportResourceRequest(
            resourceName: 'users',
            format: ExportFormat::Json,
        );

        $result = $this->handler->execute($request);

        self::assertStringContainsString('application/json', $result->mimeType);
        self::assertStringEndsWith('.json', $result->filename);
        self::assertSame(1, $result->rowCount);
    }

    #[Test]
    public function throwsWhenExportNotSupported(): void
    {
        $this->resource->method('operations')->willReturn([ResourceOperation::List]);

        $request = new ExportResourceRequest(
            resourceName: 'audit_logs',
            format: ExportFormat::Csv,
        );

        $this->expectException(AdminException::class);
        $this->expectExceptionMessage('Export not supported');

        $this->handler->execute($request);
    }

    #[Test]
    public function throwsWhenNoExportableFields(): void
    {
        $this->resource->method('operations')->willReturn([ResourceOperation::Export]);
        $this->resource->method('exportableFields')->willReturn([]);

        $request = new ExportResourceRequest(
            resourceName: 'users',
            format: ExportFormat::Csv,
        );

        $this->expectException(AdminException::class);
        $this->expectExceptionMessage('No exportable fields');

        $this->handler->execute($request);
    }

    #[Test]
    public function logsAuditEventOnExport(): void
    {
        $this->resource->method('operations')->willReturn([ResourceOperation::Export]);
        $this->resource->method('exportableFields')->willReturn(['id']);
        $this->resource->method('fields')->willReturn([
            new FieldDefinition(name: 'id', type: FieldType::Integer, label: 'ID', exportable: true),
        ]);

        $this->query->method('list')->willReturn([
            'data' => [['id' => 1]],
            'total' => 1,
            'page' => 1,
            'per_page' => 10000,
        ]);

        /** @var AuditLoggerInterface&MockObject $auditLogger */
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects($this->once())->method('log')
            ->willReturn($this->createStub(AuditEntry::class));

        $handler = $this->handlerWithMocks(auditLogger: $auditLogger);

        $request = new ExportResourceRequest(
            resourceName: 'users',
            format: ExportFormat::Csv,
        );

        $handler->execute($request);
    }

    #[Test]
    public function passesFiltersToQuery(): void
    {
        $this->resource->method('operations')->willReturn([ResourceOperation::Export]);
        $this->resource->method('exportableFields')->willReturn(['id']);
        $this->resource->method('fields')->willReturn([
            new FieldDefinition(name: 'id', type: FieldType::Integer, label: 'ID', exportable: true),
        ]);

        $filters = ['status' => 'active'];

        /** @var ResourceQueryInterface&MockObject $query */
        $query = $this->createMock(ResourceQueryInterface::class);
        $query->expects($this->once())
            ->method('list')
            ->with(
                $this->resource,
                $filters,
                [],
                1,
                5000,
            )
            ->willReturn([
                'data' => [],
                'total' => 0,
                'page' => 1,
                'per_page' => 5000,
            ]);

        $handler = $this->handlerWithMocks(query: $query);

        $request = new ExportResourceRequest(
            resourceName: 'users',
            format: ExportFormat::Csv,
            filters: $filters,
            maxRows: 5000,
        );

        $handler->execute($request);
    }
}
