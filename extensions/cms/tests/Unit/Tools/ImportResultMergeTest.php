<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Tools;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Tools\ImportResult;

#[CoversClass(ImportResult::class)]
final class ImportResultMergeTest extends TestCase
{
    #[Test]
    public function mergeAddsCounts(): void
    {
        $a = new ImportResult(
            created: ['content' => 5, 'media' => 2],
            updated: ['content' => 1],
            skipped: [],
            warnings: ['Warning A'],
            errors: [],
            dryRun: false,
        );

        $b = new ImportResult(
            created: ['content' => 3, 'taxonomy' => 4],
            updated: ['content' => 2],
            skipped: ['content' => 1],
            warnings: ['Warning B'],
            errors: ['Error B'],
            dryRun: false,
        );

        $merged = $a->merge($b);

        self::assertSame(8, $merged->created['content']);
        self::assertSame(2, $merged->created['media']);
        self::assertSame(4, $merged->created['taxonomy']);
        self::assertSame(3, $merged->updated['content']);
        self::assertSame(1, $merged->skipped['content']);
        self::assertSame(['Warning A', 'Warning B'], $merged->warnings);
        self::assertSame(['Error B'], $merged->errors);
    }

    #[Test]
    public function mergeDryRunIsTrueOnlyWhenBothTrue(): void
    {
        $dryA = new ImportResult([], [], [], [], [], true);
        $dryB = new ImportResult([], [], [], [], [], true);
        $realA = new ImportResult([], [], [], [], [], false);

        self::assertTrue($dryA->merge($dryB)->dryRun);
        self::assertFalse($dryA->merge($realA)->dryRun);
    }

    #[Test]
    public function totalCreatedSumsAllTypes(): void
    {
        $result = new ImportResult(
            created: ['content' => 10, 'media' => 5],
            updated: [],
            skipped: [],
            warnings: [],
            errors: [],
            dryRun: false,
        );

        self::assertSame(15, $result->totalCreated());
    }

    #[Test]
    public function totalUpdatedSumsAllTypes(): void
    {
        $result = new ImportResult(
            created: [],
            updated: ['content' => 3, 'taxonomy' => 2],
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
            skipped: ['content' => 7],
            warnings: [],
            errors: [],
            dryRun: false,
        );

        self::assertSame(7, $result->totalSkipped());
    }

    #[Test]
    public function hasErrorsReturnsTrueWhenErrorsExist(): void
    {
        $result = new ImportResult([], [], [], [], ['Something failed'], false);

        self::assertTrue($result->hasErrors());
    }

    #[Test]
    public function hasErrorsReturnsFalseWhenNoErrors(): void
    {
        $result = new ImportResult([], [], [], [], [], false);

        self::assertFalse($result->hasErrors());
    }

    #[Test]
    public function toArrayReturnsCorrectStructure(): void
    {
        $result = new ImportResult(
            created: ['content' => 1],
            updated: ['media' => 2],
            skipped: [],
            warnings: ['w'],
            errors: ['e'],
            dryRun: true,
        );

        $array = $result->toArray();

        self::assertSame(['content' => 1], $array['created']);
        self::assertSame(['media' => 2], $array['updated']);
        self::assertSame([], $array['skipped']);
        self::assertSame(['w'], $array['warnings']);
        self::assertSame(['e'], $array['errors']);
        self::assertTrue($array['dry_run']);
    }
}
