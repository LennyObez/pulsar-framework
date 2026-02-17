<?php

declare(strict_types=1);

namespace Pulsar\DataProtection\Dsar;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;
use ZipArchive;

use function count;
use function date;
use function is_dir;
use function json_encode;
use function mkdir;
use function sprintf;

use const DIRECTORY_SEPARATOR;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Assembles collected DSAR data into a downloadable ZIP package.
 *
 * The package contains:
 * - manifest.json: Metadata about the data export
 * - data/{source}/{category}.json: Structured data per source
 * - attachments/{source}/{filename}: File attachments
 */
#[Api(since: '1.0.0')]
final readonly class DsarPackager
{
    public function __construct(
        private string $outputDirectory,
    ) {}

    /**
     * Build a ZIP data package for a DSAR.
     *
     * @param DsarRequest $request The DSAR request
     * @param list<DsarDataSet> $dataSets Collected data from all sources
     *
     * @return string Absolute path to the created ZIP file
     *
     * @throws RuntimeException If the ZIP cannot be created
     */
    #[NoDiscard]
    public function package(DsarRequest $request, array $dataSets): string
    {
        if (!is_dir($this->outputDirectory)) {
            mkdir($this->outputDirectory, 0o750, true);
        }

        $filename = sprintf('dsar-%s-%s.zip', $request->id, date('Ymd'));
        $zipPath = $this->outputDirectory . DIRECTORY_SEPARATOR . $filename;

        $zip = new ZipArchive();
        $result = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($result !== true) {
            throw new RuntimeException('Failed to create DSAR package: ' . $zipPath);
        }

        // Add manifest
        $manifest = [
            'request_id' => $request->id,
            'subject_id' => $request->subjectId,
            'generated_at' => date('c'),
            'deadline' => $request->deadline->format('c'),
            'sources' => [],
        ];

        foreach ($dataSets as $dataSet) {
            $manifest['sources'][] = [
                'name' => $dataSet->sourceName,
                'category' => $dataSet->category,
                'record_count' => count($dataSet->records),
                'attachment_count' => count($dataSet->attachments),
            ];

            // Add data records
            if ($dataSet->records !== []) {
                $dataPath = sprintf('data/%s/%s.json', $dataSet->sourceName, $dataSet->category);
                $zip->addFromString(
                    $dataPath,
                    json_encode($dataSet->records, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                );
            }

            // Add attachments
            foreach ($dataSet->attachments as $attachment) {
                $attachmentPath = sprintf('attachments/%s/%s', $dataSet->sourceName, $attachment->filename);
                $zip->addFromString($attachmentPath, $attachment->content);
            }
        }

        $zip->addFromString(
            'manifest.json',
            json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );

        $zip->close();

        return $zipPath;
    }
}
