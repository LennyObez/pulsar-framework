<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\ImportExport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\ImportExport\ImportResult;

#[CoversClass(ImportResult::class)]
final class ImportResultTest extends TestCase
{
    #[Test]
    public function reportsCorrectTotals(): void
    {
        $result = new ImportResult(
            providerName: 'cms',
            created: ['content' => 5, 'menus' => 2],
            updated: ['content' => 1],
            skipped: ['settings' => 3],
            warnings: ['Missing author for 1 entry'],
            errors: [],
            dryRun: false,
        );

        self::assertSame(7, $result->totalCreated());
        self::assertSame(1, $result->totalUpdated());
        self::assertSame(3, $result->totalSkipped());
        self::assertFalse($result->hasErrors());
    }

    #[Test]
    public function detectsErrors(): void
    {
        $result = new ImportResult(
            providerName: 'forum',
            created: [],
            updated: [],
            skipped: [],
            warnings: [],
            errors: ['Invalid category structure'],
            dryRun: true,
        );

        self::assertTrue($result->hasErrors());
        self::assertTrue($result->dryRun);
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $result = new ImportResult(
            providerName: 'analytics',
            created: ['sites' => 2],
            updated: [],
            skipped: ['goals' => 1],
            warnings: [],
            errors: [],
            dryRun: true,
        );

        $array = $result->toArray();

        self::assertSame('analytics', $array['provider']);
        self::assertSame(['sites' => 2], $array['created']);
        self::assertSame(['goals' => 1], $array['skipped']);
        self::assertTrue($array['dry_run']);
    }

    #[Test]
    public function emptyResultReportsZeroTotals(): void
    {
        $result = new ImportResult(
            providerName: 'empty',
            created: [],
            updated: [],
            skipped: [],
            warnings: [],
            errors: [],
            dryRun: false,
        );

        self::assertSame(0, $result->totalCreated());
        self::assertSame(0, $result->totalUpdated());
        self::assertSame(0, $result->totalSkipped());
        self::assertFalse($result->hasErrors());
    }
}
