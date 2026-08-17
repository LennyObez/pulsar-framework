<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Tools;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Tools\DuplicateResolutionPolicy;
use Pulsar\Extension\Cms\Tools\ImportAnalysisResult;
use Pulsar\Extension\Cms\Tools\ImportReport;

#[CoversClass(ImportReport::class)]
#[CoversClass(ImportAnalysisResult::class)]
final class ImportReportTest extends TestCase
{
    // --- ImportReport ---

    #[Test]
    public function importReportConstructionAndToArray(): void
    {
        $report = new ImportReport(
            created: 10,
            updated: 5,
            skipped: 3,
            failed: 2,
            errors: ['Entity X failed: duplicate key', 'Entity Y failed: invalid format'],
            entityBreakdown: [
                'content' => ['created' => 8, 'updated' => 3, 'skipped' => 2, 'failed' => 1],
                'media' => ['created' => 2, 'updated' => 2, 'skipped' => 1, 'failed' => 1],
            ],
        );

        self::assertSame(10, $report->created);
        self::assertSame(5, $report->updated);
        self::assertSame(3, $report->skipped);
        self::assertSame(2, $report->failed);
        self::assertCount(2, $report->errors);
        self::assertCount(2, $report->entityBreakdown);

        $array = $report->toArray();
        self::assertSame(10, $array['created']);
        self::assertSame(5, $array['updated']);
        self::assertSame(3, $array['skipped']);
        self::assertSame(2, $array['failed']);
        self::assertSame(['Entity X failed: duplicate key', 'Entity Y failed: invalid format'], $array['errors']);
        $breakdown = $array['entity_breakdown'];
        self::assertIsArray($breakdown);
        $contentBreakdown = $breakdown['content'];
        self::assertIsArray($contentBreakdown);
        self::assertSame(8, $contentBreakdown['created']);
    }

    #[Test]
    public function importReportZeroCounts(): void
    {
        $report = new ImportReport(0, 0, 0, 0, [], []);

        $array = $report->toArray();
        self::assertSame(0, $array['created']);
        self::assertSame([], $array['errors']);
        self::assertSame([], $array['entity_breakdown']);
    }

    // --- ImportAnalysisResult ---

    #[Test]
    public function importAnalysisResultConstructionAndToArray(): void
    {
        $result = new ImportAnalysisResult(
            totalEntities: 42,
            duplicatesByType: ['content' => 3, 'media' => 1],
            missingDependencies: ['taxonomy:cat-1', 'media:img-99'],
            entityCounts: ['content' => 30, 'media' => 10, 'taxonomy' => 2],
        );

        self::assertSame(42, $result->totalEntities);
        self::assertSame(3, $result->duplicatesByType['content']);
        self::assertCount(2, $result->missingDependencies);
        self::assertSame(30, $result->entityCounts['content']);

        $array = $result->toArray();
        self::assertSame(42, $array['total_entities']);
        self::assertSame(['content' => 3, 'media' => 1], $array['duplicates_by_type']);
        self::assertSame(['taxonomy:cat-1', 'media:img-99'], $array['missing_dependencies']);
        self::assertSame(['content' => 30, 'media' => 10, 'taxonomy' => 2], $array['entity_counts']);
    }

    #[Test]
    public function importAnalysisResultEmptyBundle(): void
    {
        $result = new ImportAnalysisResult(0, [], [], []);

        self::assertSame(0, $result->totalEntities);
        $array = $result->toArray();
        self::assertSame([], $array['duplicates_by_type']);
        self::assertSame([], $array['missing_dependencies']);
    }

    // --- DuplicateResolutionPolicy ---

    #[Test]
    public function duplicateResolutionPolicyCases(): void
    {
        $cases = DuplicateResolutionPolicy::cases();
        self::assertCount(4, $cases);

        self::assertSame('replace', DuplicateResolutionPolicy::Replace->value);
        self::assertSame('import_as_new', DuplicateResolutionPolicy::ImportAsNew->value);
        self::assertSame('skip', DuplicateResolutionPolicy::Skip->value);
        self::assertSame('merge', DuplicateResolutionPolicy::Merge->value);
    }

    #[Test]
    public function duplicateResolutionPolicyFromString(): void
    {
        $policy = DuplicateResolutionPolicy::from('skip');
        self::assertSame(DuplicateResolutionPolicy::Skip, $policy);

        $tryFrom = DuplicateResolutionPolicy::tryFrom('nonexistent');
        self::assertNull($tryFrom);
    }
}
