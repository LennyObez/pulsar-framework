<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Tools;

use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Cms\Media\MediaDiskInterface;
use Pulsar\Extension\Cms\Media\MediaRepositoryInterface;
use Pulsar\Extension\Cms\Tools\ExportOptions;
use Pulsar\Extension\Cms\Tools\MediaBundleExporterInterface;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use RuntimeException;
use ZipArchive;

use function bin2hex;
use function count;
use function date;
use function is_array;
use function json_encode;
use function random_bytes;
use function sodium_crypto_generichash;
use function sys_get_temp_dir;
use function unlink;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const SODIUM_CRYPTO_GENERICHASH_BYTES;

/**
 * Creates ZIP bundles containing CMS export data and media files.
 *
 * ZIP structure:
 *  - manifest.json: schema version, timestamp, entity counts, evidence hash
 *  - data.json: export bundle data
 *  - media/{filename}: actual media files from storage
 */
#[Internal(reason: 'Media bundle export internals; use MediaBundleExporterInterface')]
/**
 * @psalm-api Bound to MediaBundleExporterInterface in the CMS service provider;
 *            resolved from the DI container, never instantiated by name.
 */
final readonly class MediaBundleExporter implements MediaBundleExporterInterface
{
    private const string SCHEMA_VERSION = '1.0.0';

    public function __construct(
        private ExportBundleGenerator $bundleGenerator,
        private MediaRepositoryInterface $mediaRepository,
        private MediaDiskInterface $mediaDisk,
        private ?AuditLoggerInterface $auditLogger,
    ) {}

    public function exportZip(ExportOptions $options): string
    {
        $bundle = $this->bundleGenerator->exportBundle($options);

        $dataJson = json_encode(
            $bundle->data,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        $mediaFiles = $this->collectMediaFiles($options);
        $entityCounts = $this->countEntities($bundle->data);

        $evidenceHash = bin2hex(
            sodium_crypto_generichash($dataJson, '', SODIUM_CRYPTO_GENERICHASH_BYTES),
        );

        $manifest = [
            'schema_version' => self::SCHEMA_VERSION,
            'exported_at' => date('c'),
            'entity_counts' => $entityCounts,
            'media_file_count' => count($mediaFiles),
            'evidence_hash' => $evidenceHash,
            'scope' => $options->scope,
            'pii_included' => $options->includePii,
        ];

        $manifestJson = json_encode(
            $manifest,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        $tempPath = sys_get_temp_dir() . '/cms-export-' . bin2hex(random_bytes(8)) . '.zip';

        $zip = new ZipArchive();
        $result = $zip->open($tempPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($result !== true) {
            throw new RuntimeException("Failed to create ZIP archive at $tempPath");
        }

        $zip->addFromString('manifest.json', $manifestJson);
        $zip->addFromString('data.json', $dataJson);

        foreach ($mediaFiles as $mediaFile) {
            $contents = $this->mediaDisk->read($mediaFile['storage_path']);
            $zip->addFromString('media/' . $mediaFile['filename'], $contents);
        }

        $zip->close();

        $this->verifyCreatedZip($tempPath, $evidenceHash);

        $this->auditLogger?->log(
            AuditEvent::DataAccess,
            AuditOutcome::Success,
            null,
            'cms.export.zip_created',
            'cms:export',
            [
                'evidence_hash' => $evidenceHash,
                'media_files' => count($mediaFiles),
                'scope' => $options->scope,
            ],
        );

        return $tempPath;
    }

    /**
     * Collect media file metadata for inclusion in the ZIP.
     *
     * @return list<array{id: string, filename: string, storage_path: string}>
     */
    private function collectMediaFiles(ExportOptions $options): array
    {
        $files = [];
        $page = 1;
        $perPage = 500;

        // Paginate through all media assets to avoid unbounded memory usage.
        // Previously used a single query with perPage=10000 which could OOM
        // on large media libraries.
        do {
            $result = $this->mediaRepository->listAssets(
                tenantId: $options->tenantId,
                page: $page,
                perPage: $perPage,
            );

            foreach ($result->items as $asset) {
                if (!$this->mediaDisk->exists($asset->storagePath)) {
                    continue;
                }

                $files[] = [
                    'id' => $asset->id,
                    'filename' => $asset->filename,
                    'storage_path' => $asset->storagePath,
                ];
            }

            $page++;
        } while (count($result->items) === $perPage);

        return $files;
    }

    /**
     * Count entities per type in the export data.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, int>
     */
    private function countEntities(array $data): array
    {
        $counts = [];

        foreach ($data as $type => $items) {
            if (is_array($items)) {
                $counts[$type] = count($items);
            }
        }

        return $counts;
    }

    /**
     * Re-read the created ZIP and verify the evidence hash matches.
     */
    private function verifyCreatedZip(string $zipPath, string $expectedHash): void
    {
        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            unlink($zipPath);

            throw new RuntimeException('Failed to re-open ZIP for integrity verification');
        }

        $manifestContent = $zip->getFromName('manifest.json');
        $zip->close();

        if ($manifestContent === false) {
            unlink($zipPath);

            throw new RuntimeException('ZIP archive missing manifest.json');
        }

        /** @var array{evidence_hash?: string} $manifest */
        $manifest = json_decode($manifestContent, true, flags: JSON_THROW_ON_ERROR);

        if (($manifest['evidence_hash'] ?? '') !== $expectedHash) {
            unlink($zipPath);

            throw new RuntimeException('ZIP integrity verification failed: evidence hash mismatch');
        }
    }
}
