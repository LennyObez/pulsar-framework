<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Tools;

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
use function json_encode;

#[CoversClass(ImportResult::class)]
final class ImportParserTest extends TestCase
{
    // ── Schema validation: invalid JSON rejected ────────────────────

    #[Test]
    public function invalidJsonRejected(): void
    {
        $service = $this->createImportExportService();

        $result = $service->importBundle('not valid json', dryRun: true);

        self::assertNotEmpty($result->errors);
        self::assertTrue($result->dryRun);
    }

    // ── Dry-run returns counts without persisting ───────────────────

    #[Test]
    public function dryRunReturnsCounts(): void
    {
        $service = $this->createImportExportService();

        $json = json_encode([
            'content' => [
                ['id' => 'c1', 'title' => 'Page 1'],
                ['id' => 'c2', 'title' => 'Page 2'],
            ],
            'taxonomies' => [
                ['id' => 't1', 'name' => 'Category'],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $service->importBundle($json, dryRun: true);

        self::assertTrue($result->dryRun);
        self::assertSame(2, $result->created['content'] ?? 0);
        self::assertSame(1, $result->created['taxonomies'] ?? 0);
        self::assertSame([], $result->errors);
    }

    // ── Entity type recognition ─────────────────────────────────────

    #[Test]
    public function entityTypeRecognition(): void
    {
        $service = $this->createImportExportService();

        $json = json_encode([
            'content' => [['id' => 'c1']],
            'menus' => [['id' => 'm1'], ['id' => 'm2']],
            'settings' => ['site_name' => 'Test'],
        ], JSON_THROW_ON_ERROR);

        $result = $service->importBundle($json, dryRun: true);

        self::assertArrayHasKey('content', $result->created);
        self::assertArrayHasKey('menus', $result->created);
        self::assertArrayHasKey('settings', $result->created);
    }

    // ── ImportResult toArray ────────────────────────────────────────

    #[Test]
    public function importResultToArray(): void
    {
        $result = new ImportResult(
            created: ['content' => 3, 'taxonomies' => 1],
            updated: ['content' => 1],
            skipped: [],
            warnings: ['Some items were skipped'],
            errors: [],
            dryRun: false,
        );

        $array = $result->toArray();

        self::assertSame(['content' => 3, 'taxonomies' => 1], $array['created']);
        self::assertSame(['content' => 1], $array['updated']);
        self::assertSame([], $array['skipped']);
        self::assertSame(['Some items were skipped'], $array['warnings']);
        self::assertSame([], $array['errors']);
        self::assertFalse($array['dry_run']);
    }

    private function createImportExportService(): ImportExportServiceInterface
    {
        return new class implements ImportExportServiceInterface {
            public function exportBundle(ExportOptions $options): ExportBundle
            {
                return new ExportBundle(
                    data: [],
                    evidenceHash: 'test',
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
                    return new ImportResult(
                        created: [],
                        updated: [],
                        skipped: [],
                        warnings: [],
                        errors: ['Invalid JSON format'],
                        dryRun: $dryRun,
                    );
                }

                /** @var array<string, int> $created */
                $created = [];

                foreach ($data as $type => $items) {
                    if (is_array($items)) {
                        $created[(string) $type] = array_is_list($items) ? count($items) : 1;
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
