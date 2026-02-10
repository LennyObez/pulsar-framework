<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Internal\Export;

use function count;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Admin\Contracts\ExportDriverInterface;
use Pulsar\Extension\Admin\Domain\ExportFormat;

/**
 * JSON export driver with scalar-only value enforcement.
 */
#[Internal]
final readonly class JsonExportDriver implements ExportDriverInterface
{
    #[Override]
    public function format(): ExportFormat
    {
        return ExportFormat::Json;
    }

    #[Override]
    public function mimeType(): string
    {
        return 'application/json; charset=utf-8';
    }

    #[Override]
    public function fileExtension(): string
    {
        return 'json';
    }

    #[Override]
    public function export(array $columns, array $rows): string
    {
        $output = [];
        foreach ($rows as $row) {
            $entry = [];
            foreach ($columns as $col) {
                $entry[$col] = $row[$col] ?? null;
            }
            $output[] = $entry;
        }

        return json_encode(
            ['data' => $output, 'columns' => $columns, 'total' => count($output)],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
        );
    }
}
