<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Tools;

use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentTranslation;

use function array_map;
use function fclose;
use function fopen;
use function fputcsv;
use function rewind;
use function str_contains;
use function stream_get_contents;

/**
 * Exports CMS content items as a flat CSV file.
 *
 * One row per content item with key fields from both the Content aggregate
 * and the ContentTranslation. Uses php://memory streams for efficient
 * in-memory CSV generation.
 */
#[Internal(reason: 'Import/export internals; use ImportExportServiceInterface')]
/**
 * @psalm-api Resolved from the DI container by ImportExportService and admin
 *            controllers; not instantiated by name.
 */
final readonly class CsvContentExporter
{
    private const array COLUMNS = [
        'id',
        'content_type',
        'status',
        'locale',
        'title',
        'slug',
        'path',
        'body',
        'excerpt',
        'meta_title',
        'meta_description',
        'author_id',
        'created_at',
        'published_at',
    ];

    /**
     * Export content items as a CSV string.
     *
     * @param list<array{content: Content, translation: ContentTranslation}> $items
     */
    public function export(array $items): string
    {
        $stream = fopen('php://memory', 'r+');

        if ($stream === false) {
            return '';
        }

        fputcsv($stream, self::COLUMNS, escape: '');

        foreach ($items as $item) {
            $content = $item['content'];
            $translation = $item['translation'];

            fputcsv($stream, array_map(
                $this->sanitizeFormulaInjection(...),
                [
                    $content->id,
                    $content->contentType->value,
                    $content->status->value,
                    $translation->locale,
                    $translation->title,
                    $translation->slugSegment,
                    $translation->path,
                    $translation->body,
                    $translation->excerpt ?? '',
                    $translation->metaTitle ?? '',
                    $translation->metaDescription ?? '',
                    $content->authorId,
                    $content->createdAt->format('c'),
                    $content->publishedAt?->format('c') ?? '',
                ],
            ), escape: '');
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv !== false ? $csv : '';
    }

    /**
     * Prevent CSV formula injection by prefixing dangerous leading characters.
     */
    private function sanitizeFormulaInjection(string $value): string
    {
        if ($value !== '' && str_contains("=+-@\t\r", $value[0])) {
            return "\t" . $value;
        }

        return $value;
    }
}
