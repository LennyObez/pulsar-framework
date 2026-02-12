<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Internal\Export;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Admin\Contracts\ExportDriverInterface;
use Pulsar\Extension\Admin\Domain\ExportFormat;

use function fclose;
use function fopen;
use function fputcsv;
use function is_bool;
use function is_string;
use function rewind;
use function str_contains;
use function stream_get_contents;

/**
 * CSV export driver with scalar-only value enforcement.
 */
#[Internal]
final readonly class CsvExportDriver implements ExportDriverInterface
{
    #[Override]
    public function format(): ExportFormat
    {
        return ExportFormat::Csv;
    }

    #[Override]
    public function mimeType(): string
    {
        return 'text/csv; charset=utf-8';
    }

    #[Override]
    public function fileExtension(): string
    {
        return 'csv';
    }

    #[Override]
    public function export(array $columns, array $rows): string
    {
        /** @var resource $handle */
        $handle = fopen('php://temp', 'r+b');

        fputcsv($handle, $columns, ',', '"', '');

        foreach ($rows as $row) {
            $values = [];
            foreach ($columns as $col) {
                $value = $row[$col] ?? null;
                $values[] = $this->formatValue($value);
            }
            fputcsv($handle, $values, ',', '"', '');
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return $content !== false ? $content : '';
    }

    private function formatValue(string|int|float|bool|null $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        $str = (string) $value;

        // Protect against CSV formula injection
        if ($str !== '' && is_string($value) && str_contains("=+-@\t\r", $str[0])) {
            return "\t" . $str;
        }

        return $str;
    }
}
