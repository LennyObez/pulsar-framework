<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extension\Cms;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Tools\ExportBundle;
use Pulsar\Extension\Cms\Tools\ExportOptions;
use Pulsar\Extension\Cms\Tools\ImportExportServiceInterface;
use Pulsar\Extension\Cms\Tools\ImportResult;

use function count;
use function is_array;
use function json_decode;
use function json_encode;

#[CoversClass(ExportBundle::class)]
#[CoversClass(ImportResult::class)]
#[CoversClass(ExportOptions::class)]
final class ImportExportIntegrationTest extends TestCase
{
    #[Test]
    public function test_export_then_import_dry_run(): void
    {
        $service = $this->createImportExportService();

        // Step 1: Export bundle
        $options = new ExportOptions(
            scope: ['content', 'taxonomies'],
            locales: ['en'],
            includePii: false,
        );

        $bundle = $service->exportBundle($options);

        // Verify export hash
        self::assertNotEmpty($bundle->evidenceHash);
        self::assertSame(['content', 'taxonomies'], $bundle->scope);
        self::assertFalse($bundle->piiIncluded);
        self::assertArrayHasKey('content', $bundle->data);
        self::assertArrayHasKey('taxonomies', $bundle->data);

        // Step 2: Import dry-run
        $json = json_encode($bundle->data, JSON_THROW_ON_ERROR);
        $importResult = $service->importBundle($json, dryRun: true);

        // Step 3: Verify counts match
        self::assertTrue($importResult->dryRun);
        self::assertSame([], $importResult->errors);

        /** @var list<mixed> $contentItems */
        $contentItems = $bundle->data['content'] ?? [];
        $exportContentCount = count($contentItems);
        /** @var list<mixed> $taxonomyItems */
        $taxonomyItems = $bundle->data['taxonomies'] ?? [];
        $exportTaxonomyCount = count($taxonomyItems);

        self::assertSame($exportContentCount, $importResult->created['content'] ?? 0);
        self::assertSame($exportTaxonomyCount, $importResult->created['taxonomies'] ?? 0);
    }

    #[Test]
    public function test_export_with_pii_redaction(): void
    {
        $service = $this->createImportExportService();

        $bundleWithPii = $service->exportBundle(new ExportOptions(
            scope: ['content'],
            includePii: true,
        ));

        $bundleWithoutPii = $service->exportBundle(new ExportOptions(
            scope: ['content'],
            includePii: false,
        ));

        self::assertTrue($bundleWithPii->piiIncluded);
        self::assertFalse($bundleWithoutPii->piiIncluded);

        // PII-redacted bundle should have emails redacted
        /** @var list<array<string, mixed>> $contentItems */
        $contentItems = $bundleWithoutPii->data['content'] ?? [];

        foreach ($contentItems as $item) {
            if (isset($item['email'])) {
                self::assertSame('[redacted]', $item['email']);
            }
        }
    }

    #[Test]
    public function test_import_execute_mode(): void
    {
        $service = $this->createImportExportService();

        $json = json_encode([
            'content' => [
                ['id' => 'c1', 'title' => 'Page 1'],
                ['id' => 'c2', 'title' => 'Page 2'],
            ],
            'taxonomies' => [
                ['id' => 't1', 'name' => 'Categories'],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $service->importBundle($json, dryRun: false);

        self::assertFalse($result->dryRun);
        self::assertSame(2, $result->created['content']);
        self::assertSame(1, $result->created['taxonomies']);
    }

    private function createImportExportService(): ImportExportServiceInterface
    {
        return new class implements ImportExportServiceInterface {
            /** @var array<string, list<array<string, mixed>>> */
            private array $store = [
                'content' => [
                    ['id' => 'c1', 'title' => 'Home', 'email' => 'author@example.com'],
                    ['id' => 'c2', 'title' => 'About', 'email' => 'editor@example.com'],
                ],
                'taxonomies' => [
                    ['id' => 't1', 'name' => 'Categories'],
                ],
            ];

            public function exportBundle(ExportOptions $options): ExportBundle
            {
                $data = [];

                foreach ($options->scope as $scope) {
                    $items = $this->store[$scope] ?? [];

                    if (!$options->includePii) {
                        $items = array_map(static function (array $item): array {
                            if (isset($item['email'])) {
                                $item['email'] = '[redacted]';
                            }

                            return $item;
                        }, $items);
                    }

                    $data[$scope] = $items;
                }

                $json = json_encode($data, JSON_THROW_ON_ERROR);

                return new ExportBundle(
                    data: $data,
                    evidenceHash: hash('xxh128', $json),
                    createdAt: new DateTimeImmutable(),
                    scope: $options->scope,
                    piiIncluded: $options->includePii,
                );
            }

            public function importBundle(string $jsonContent, bool $dryRun = true): ImportResult
            {
                /** @var array<string, mixed>|null $data */
                $data = json_decode($jsonContent, true);

                if ($data === null) {
                    return new ImportResult([], [], [], [], ['Invalid JSON'], $dryRun);
                }

                /** @var array<string, int> $created */
                $created = [];

                foreach ($data as $type => $items) {
                    if (is_array($items) && array_is_list($items)) {
                        $created[(string) $type] = count($items);
                    }
                }

                return new ImportResult(
                    created: $created,
                    updated: [],
                    skipped: [],
                    warnings: [],
                    errors: [],
                    dryRun: $dryRun,
                );
            }

            public function importSiteDefinition(string $jsonContent, bool $dryRun = true): ImportResult
            {
                return $this->importBundle($jsonContent, $dryRun);
            }
        };
    }
}
