<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Tools;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Tools\ImportResult;

#[CoversClass(ImportResult::class)]
final class ImportResultTest extends TestCase
{
    #[Test]
    public function totalCreatedSumsAllTypes(): void
    {
        $result = new ImportResult(
            created: ['content' => 5, 'taxonomies' => 3, 'menus' => 2],
            updated: [],
            skipped: [],
            warnings: [],
            errors: [],
            dryRun: false,
        );

        self::assertSame(10, $result->totalCreated());
    }

    #[Test]
    public function totalUpdatedSumsAllTypes(): void
    {
        $result = new ImportResult(
            created: [],
            updated: ['content' => 4, 'taxonomies' => 1],
            skipped: [],
            warnings: [],
            errors: [],
            dryRun: false,
        );

        self::assertSame(5, $result->totalUpdated());
    }

    #[Test]
    public function totalSkippedSumsAllTypes(): void
    {
        $result = new ImportResult(
            created: [],
            updated: [],
            skipped: ['content' => 2, 'menus' => 1],
            warnings: [],
            errors: [],
            dryRun: false,
        );

        self::assertSame(3, $result->totalSkipped());
    }

    #[Test]
    public function hasErrorsReturnsTrueWhenErrorsPresent(): void
    {
        $result = new ImportResult(
            created: [],
            updated: [],
            skipped: [],
            warnings: [],
            errors: ['Something failed'],
            dryRun: false,
        );

        self::assertTrue($result->hasErrors());
    }

    #[Test]
    public function hasErrorsReturnsFalseWhenNoErrors(): void
    {
        $result = new ImportResult(
            created: [],
            updated: [],
            skipped: [],
            warnings: ['Minor warning'],
            errors: [],
            dryRun: false,
        );

        self::assertFalse($result->hasErrors());
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $result = new ImportResult(
            created: ['content' => 3],
            updated: ['menus' => 1],
            skipped: ['taxonomies' => 2],
            warnings: ['Duplicate slug'],
            errors: ['Parse error'],
            dryRun: true,
        );

        $array = $result->toArray();

        self::assertSame(['content' => 3], $array['created']);
        self::assertSame(['menus' => 1], $array['updated']);
        self::assertSame(['taxonomies' => 2], $array['skipped']);
        self::assertSame(['Duplicate slug'], $array['warnings']);
        self::assertSame(['Parse error'], $array['errors']);
        self::assertTrue($array['dry_run']);
    }

    #[Test]
    public function mergesCombinesCreatedCounts(): void
    {
        $a = new ImportResult(
            created: ['content' => 3, 'taxonomies' => 2],
            updated: [],
            skipped: [],
            warnings: [],
            errors: [],
            dryRun: false,
        );

        $b = new ImportResult(
            created: ['content' => 1, 'menus' => 4],
            updated: [],
            skipped: [],
            warnings: [],
            errors: [],
            dryRun: false,
        );

        $merged = $a->merge($b);

        self::assertSame(4, $merged->created['content']);
        self::assertSame(2, $merged->created['taxonomies']);
        self::assertSame(4, $merged->created['menus']);
    }

    #[Test]
    public function mergeCombinesUpdatedAndSkippedCounts(): void
    {
        $a = new ImportResult(
            created: [],
            updated: ['content' => 2],
            skipped: ['menus' => 1],
            warnings: [],
            errors: [],
            dryRun: false,
        );

        $b = new ImportResult(
            created: [],
            updated: ['content' => 3, 'taxonomies' => 1],
            skipped: ['menus' => 2],
            warnings: [],
            errors: [],
            dryRun: false,
        );

        $merged = $a->merge($b);

        self::assertSame(5, $merged->updated['content']);
        self::assertSame(1, $merged->updated['taxonomies']);
        self::assertSame(3, $merged->skipped['menus']);
    }

    #[Test]
    public function mergeConcatenatesWarningsAndErrors(): void
    {
        $a = new ImportResult(
            created: [],
            updated: [],
            skipped: [],
            warnings: ['warn-a'],
            errors: ['err-a'],
            dryRun: false,
        );

        $b = new ImportResult(
            created: [],
            updated: [],
            skipped: [],
            warnings: ['warn-b'],
            errors: ['err-b'],
            dryRun: false,
        );

        $merged = $a->merge($b);

        self::assertSame(['warn-a', 'warn-b'], $merged->warnings);
        self::assertSame(['err-a', 'err-b'], $merged->errors);
    }

    #[Test]
    public function mergeSetsExecuteModeIfEitherIsExecute(): void
    {
        $dryRun = new ImportResult(
            created: [],
            updated: [],
            skipped: [],
            warnings: [],
            errors: [],
            dryRun: true,
        );

        $execute = new ImportResult(
            created: [],
            updated: [],
            skipped: [],
            warnings: [],
            errors: [],
            dryRun: false,
        );

        $merged = $dryRun->merge($execute);

        self::assertFalse($merged->dryRun);
    }

    #[Test]
    public function mergePreservesDryRunWhenBothAreDryRun(): void
    {
        $a = new ImportResult(
            created: [],
            updated: [],
            skipped: [],
            warnings: [],
            errors: [],
            dryRun: true,
        );

        $b = new ImportResult(
            created: [],
            updated: [],
            skipped: [],
            warnings: [],
            errors: [],
            dryRun: true,
        );

        $merged = $a->merge($b);

        self::assertTrue($merged->dryRun);
    }

    #[Test]
    public function emptyResultsHaveZeroTotals(): void
    {
        $result = new ImportResult(
            created: [],
            updated: [],
            skipped: [],
            warnings: [],
            errors: [],
            dryRun: true,
        );

        self::assertSame(0, $result->totalCreated());
        self::assertSame(0, $result->totalUpdated());
        self::assertSame(0, $result->totalSkipped());
        self::assertFalse($result->hasErrors());
    }

    /**
     * @return iterable<string, array{ImportResult, ImportResult}>
     */
    public static function mergeIdentityProvider(): iterable
    {
        $base = new ImportResult(
            created: ['content' => 5],
            updated: ['menus' => 2],
            skipped: ['taxonomies' => 1],
            warnings: ['w1'],
            errors: [],
            dryRun: false,
        );

        $empty = new ImportResult(
            created: [],
            updated: [],
            skipped: [],
            warnings: [],
            errors: [],
            dryRun: false,
        );

        yield 'merge with empty preserves values' => [$base, $empty];
    }

    #[Test]
    #[DataProvider('mergeIdentityProvider')]
    public function mergeWithEmptyPreservesOriginal(ImportResult $base, ImportResult $empty): void
    {
        $merged = $base->merge($empty);

        self::assertSame($base->totalCreated(), $merged->totalCreated());
        self::assertSame($base->totalUpdated(), $merged->totalUpdated());
        self::assertSame($base->totalSkipped(), $merged->totalSkipped());
        self::assertSame($base->warnings, $merged->warnings);
    }
}
