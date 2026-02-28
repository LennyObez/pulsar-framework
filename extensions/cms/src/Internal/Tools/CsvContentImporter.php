<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Tools;

use Pulsar\Api\Internal;

use function array_combine;
use function count;
use function fclose;
use function fgetcsv;
use function fopen;
use function fwrite;
use function is_array;
use function rewind;

/**
 * Parses CSV data into CMS content data arrays for import.
 *
 * Expects a header row matching the column names produced by {@see CsvContentExporter}.
 * Maps each row into content and translation data arrays.
 */
#[Internal(reason: 'Import/export internals — use ImportExportServiceInterface')]
final readonly class CsvContentImporter
{
    /**
     * Parse a CSV string into content data arrays.
     *
     * @return list<array{content: array<string, mixed>, translation: array<string, mixed>}>
     */
    public function parse(string $csv): array
    {
        $stream = fopen('php://memory', 'r+');

        if ($stream === false) {
            return [];
        }

        fwrite($stream, $csv);
        rewind($stream);

        $headers = fgetcsv($stream, escape: '');

        if (!is_array($headers) || $headers === [null]) {
            fclose($stream);

            return [];
        }

        /** @var list<string> $headers */
        $results = [];

        while (($row = fgetcsv($stream, escape: '')) !== false) {
            if ($row === null || count($row) !== count($headers)) {
                continue;
            }

            /** @var array<string, string> $mapped */
            $mapped = array_combine($headers, $row);

            $results[] = [
                'content' => [
                    'id' => $mapped['id'] ?? null,
                    'content_type' => $mapped['content_type'] ?? 'page',
                    'status' => $mapped['status'] ?? 'draft',
                    'author_id' => $mapped['author_id'] ?? 'system',
                    'created_at' => ($mapped['created_at'] ?? '') !== '' ? $mapped['created_at'] : null,
                    'published_at' => ($mapped['published_at'] ?? '') !== '' ? $mapped['published_at'] : null,
                ],
                'translation' => [
                    'locale' => $mapped['locale'] ?? 'en',
                    'title' => $mapped['title'] ?? '',
                    'slug' => $mapped['slug'] ?? '',
                    'path' => $mapped['path'] ?? '',
                    'body' => $mapped['body'] ?? '',
                    'excerpt' => ($mapped['excerpt'] ?? '') !== '' ? $mapped['excerpt'] : null,
                    'meta_title' => ($mapped['meta_title'] ?? '') !== '' ? $mapped['meta_title'] : null,
                    'meta_description' => ($mapped['meta_description'] ?? '') !== '' ? $mapped['meta_description'] : null,
                ],
            ];
        }

        fclose($stream);

        return $results;
    }
}
