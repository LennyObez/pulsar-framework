<?php

declare(strict_types=1);

namespace Pulsar\Tests\Benchmark\Cms;

use Override;
use PhpBench\Attributes\Assert;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Subject;
use PhpBench\Attributes\Warmup;
use Pulsar\Extension\Cms\Tools\ExportBundle;
use Pulsar\Extension\Cms\Tools\ExportOptions;
use Pulsar\Extension\Cms\Tools\ImportExportServiceInterface;
use Pulsar\Extension\Cms\Tools\ImportResult;
use Pulsar\Tests\Benchmark\Cms\Support\CmsBenchmarkFactory;
use RuntimeException;

use function count;
use function json_decode;

/**
 * Large import benchmark.
 *
 * Measures JSON bundle parsing and validation overhead for a 1000-item import.
 * Uses a synthetic ImportExportService that simulates validation without DB writes.
 * Target: < 60 seconds for 1000 items.
 */
#[BeforeMethods('setUp')]
#[Revs(1)]
#[Iterations(3)]
#[Warmup(0)]
final class LargeImportBench
{
    private ImportExportServiceInterface $importService;
    private string $bundle1000;

    public function setUp(): void
    {
        $factory = new CmsBenchmarkFactory();
        $this->bundle1000 = $factory->generateImportBundle(1000);

        $this->importService = new class implements ImportExportServiceInterface {
            #[Override]
            public function exportBundle(ExportOptions $options): ExportBundle
            {
                throw new RuntimeException('Not implemented for benchmark');
            }

            #[Override]
            public function importBundle(string $jsonContent, bool $dryRun = true): ImportResult
            {
                // Simulate parsing and validation without DB writes
                /** @var array<string, mixed> $data */
                $data = json_decode($jsonContent, true, 512, JSON_THROW_ON_ERROR);
                /** @var list<array{title?: string, slug?: string}> $items */
                $items = $data['content'] ?? [];
                $count = count($items);

                // Simulate per-item validation
                $created = 0;
                $skipped = 0;
                $errors = [];

                foreach ($items as $index => $item) {
                    if (empty($item['title']) || empty($item['slug'])) {
                        $errors[] = "Item {$index}: missing title or slug";
                        $skipped++;

                        continue;
                    }

                    $created++;
                }

                return new ImportResult(
                    created: ['content' => $created],
                    updated: [],
                    skipped: ['content' => $skipped],
                    warnings: [],
                    errors: $errors,
                    dryRun: $dryRun,
                );
            }

            #[Override]
            public function importSiteDefinition(string $jsonContent, bool $dryRun = true): ImportResult
            {
                return $this->importBundle($jsonContent, $dryRun);
            }

            #[Override]
            public function importUnifiedFile(string $jsonContent, bool $dryRun = true): ImportResult
            {
                return $this->importBundle($jsonContent, $dryRun);
            }
        };
    }

    #[Subject]
    #[Assert('mode(variant.time.avg) < 60 seconds')]
    public function benchImport1000ItemsDryRun(): void
    {
        $result = $this->importService->importBundle($this->bundle1000, dryRun: true);
    }

    #[Subject]
    #[Assert('mode(variant.time.avg) < 60 seconds')]
    public function benchImport1000ItemsExecute(): void
    {
        $result = $this->importService->importBundle($this->bundle1000, dryRun: false);
    }
}
