<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tools;

use Pulsar\Api\Api;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Media\MediaRepositoryInterface;

use function array_sum;
use function count;
use function is_array;
use function is_string;

/**
 * Analyzes an import bundle to detect duplicates, count entities,
 * and identify missing dependencies before executing the import.
 */
#[Api(since: '1.0.0')]
final readonly class ImportAnalyzer
{
    public function __construct(
        private ContentRepositoryInterface $contentRepo,
        private MediaRepositoryInterface $mediaRepo,
    ) {}

    /**
     * Analyze a decoded bundle data array and return analysis results.
     *
     * @param array<string, mixed> $bundleData Decoded JSON bundle data
     */
    public function analyze(array $bundleData): ImportAnalysisResult
    {
        $entityCounts = [];
        $duplicatesByType = [];
        $missingDependencies = [];

        if (isset($bundleData['content']) && is_array($bundleData['content'])) {
            $contentItems = $bundleData['content'];
            $entityCounts['content'] = count($contentItems);
            $duplicates = 0;

            foreach ($contentItems as $item) {
                if (!is_array($item)) {
                    continue;
                }

                /** @var array<string, mixed> $item */
                $slug = $item['slug'] ?? $item['slugSegment'] ?? null;
                $locale = $item['locale'] ?? 'en';

                if ($slug !== null && $slug !== '' && is_string($slug)) {
                    $resolvedLocale = is_string($locale) ? $locale : 'en';
                    $tenantId = isset($item['tenant_id']) && is_string($item['tenant_id']) ? $item['tenant_id'] : null;
                    $existing = $this->contentRepo->findByPath($resolvedLocale, $slug, $tenantId);

                    if ($existing !== null) {
                        $duplicates++;
                    }
                }
            }

            if ($duplicates > 0) {
                $duplicatesByType['content'] = $duplicates;
            }
        }

        if (isset($bundleData['taxonomies']) && is_array($bundleData['taxonomies'])) {
            $entityCounts['taxonomies'] = count($bundleData['taxonomies']);
        }

        if (isset($bundleData['menus']) && is_array($bundleData['menus'])) {
            $entityCounts['menus'] = count($bundleData['menus']);
        }

        if (isset($bundleData['settings']) && is_array($bundleData['settings'])) {
            $settingsCount = 0;

            foreach ($bundleData['settings'] as $group) {
                if (is_array($group)) {
                    $settingsCount += count($group);
                }
            }

            $entityCounts['settings'] = $settingsCount;
        }

        if (isset($bundleData['media_refs']) && is_array($bundleData['media_refs'])) {
            $mediaItems = $bundleData['media_refs'];
            $entityCounts['media_refs'] = count($mediaItems);
            $duplicates = 0;
            $missing = [];

            foreach ($mediaItems as $item) {
                if (!is_array($item)) {
                    continue;
                }

                /** @var array<string, mixed> $item */
                $id = isset($item['id']) && is_string($item['id']) ? $item['id'] : null;

                if ($id !== null) {
                    $existing = $this->mediaRepo->findById($id);

                    if ($existing !== null) {
                        $duplicates++;
                    }
                }

                $storagePath = $item['storage_path'] ?? null;

                if ($storagePath !== null && is_string($storagePath) && !isset($bundleData['media_files'])) {
                    $missing[] = 'media_file:' . $storagePath;
                }
            }

            if ($duplicates > 0) {
                $duplicatesByType['media_refs'] = $duplicates;
            }

            if ($missing !== []) {
                $missingDependencies = [...$missingDependencies, ...$missing];
            }
        }

        if (isset($bundleData['comments']) && is_array($bundleData['comments'])) {
            $entityCounts['comments'] = count($bundleData['comments']);
        }

        if (isset($bundleData['users']) && is_array($bundleData['users'])) {
            $entityCounts['users'] = count($bundleData['users']);
        }

        if (isset($bundleData['media_files']) && is_array($bundleData['media_files'])) {
            $entityCounts['media_files'] = count($bundleData['media_files']);
        }

        if (isset($bundleData['configuration']) && is_array($bundleData['configuration'])) {
            $entityCounts['configuration'] = count($bundleData['configuration']);
        }

        $totalEntities = (int) array_sum($entityCounts);

        return new ImportAnalysisResult(
            totalEntities: $totalEntities,
            duplicatesByType: $duplicatesByType,
            missingDependencies: $missingDependencies,
            entityCounts: $entityCounts,
        );
    }
}
