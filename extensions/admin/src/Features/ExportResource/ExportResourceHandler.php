<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Features\ExportResource;

use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Admin\Contracts\ExportDriverInterface;
use Pulsar\Extension\Admin\Contracts\ResourceQueryInterface;
use Pulsar\Extension\Admin\Contracts\ResourceRegistryInterface;
use Pulsar\Extension\Admin\Domain\ExportFormat;
use Pulsar\Extension\Admin\Domain\ResourceOperation;
use Pulsar\Extension\Admin\Exception\AdminException;
use Pulsar\Extension\Admin\Internal\Export\CsvExportDriver;
use Pulsar\Extension\Admin\Internal\Export\HashingStreamWrapper;
use Pulsar\Extension\Admin\Internal\Export\JsonExportDriver;
use Pulsar\Extension\Admin\Internal\Policy\FieldVisibilityFilter;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

use function count;
use function date;
use function in_array;

/**
 * Handles exporting resource data with evidence hashing and field filtering.
 */
final readonly class ExportResourceHandler
{
    public function __construct(
        private ResourceRegistryInterface $registry,
        private ResourceQueryInterface $query,
        private FieldVisibilityFilter $visibilityFilter,
        private AuditLoggerInterface $auditLogger,
    ) {}

    public function execute(ExportResourceRequest $request): ExportResourceResult
    {
        $resource = $this->registry->get($request->resourceName);

        $ops = $resource->operations();
        if (!in_array(ResourceOperation::Export, $ops, true)) {
            throw new AdminException(
                "Export not supported on resource \"$request->resourceName\"",
            );
        }

        $exportableFields = $resource->exportableFields();
        if ($exportableFields === []) {
            throw new AdminException(
                "No exportable fields defined for resource \"$request->resourceName\"",
            );
        }

        $result = $this->query->list(
            $resource,
            $request->filters,
            [],
            1,
            $request->maxRows,
        );

        $rows = array_map(
            fn(array $row): array => $this->visibilityFilter->filterForExport($resource, $row),
            $result['data'],
        );

        $driver = $this->resolveDriver($request->format);
        $output = $driver->export($exportableFields, $rows);

        $stream = new HashingStreamWrapper();
        $stream->write($output);
        $stream->close();

        $filename = "{$request->resourceName}_export_" . date('Ymd_His') . ".{$driver->fileExtension()}";

        $this->auditLogger->log(
            event: AuditEvent::DataAccess,
            outcome: AuditOutcome::Success,
            actor: null,
            action: "admin.export.$request->resourceName",
            resource: $request->resourceName,
            metadata: [
                'format' => $request->format->value,
                'row_count' => count($rows),
                'evidence_hash' => $stream->evidenceHash,
                'filename' => $filename,
            ],
        );

        return new ExportResourceResult(
            content: $output,
            mimeType: $driver->mimeType(),
            filename: $filename,
            evidenceHash: $stream->evidenceHash,
            rowCount: count($rows),
        );
    }

    private function resolveDriver(ExportFormat $format): ExportDriverInterface
    {
        return match ($format) {
            ExportFormat::Csv => new CsvExportDriver(),
            ExportFormat::Json => new JsonExportDriver(),
        };
    }
}
