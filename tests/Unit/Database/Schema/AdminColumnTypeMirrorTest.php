<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Schema;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Schema\SchemaColumnType;

use function array_column;
use function dirname;
use function file_get_contents;
use function preg_match;
use function preg_match_all;

/**
 * The admin schema builder lists the column types by hand. A case added to the enum and
 * missing there cannot be chosen in the UI; one listed there and absent from the enum
 * fails SchemaColumnType::from() on submit.
 */
#[CoversNothing]
final class AdminColumnTypeMirrorTest extends TestCase
{
    #[Test]
    public function theAdminBuilderListsEveryColumnTypeInEnumOrder(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 4) . '/extensions/admin/frontend/src/components/SchemaBuilder.ts');

        if (preg_match('/const COLUMN_TYPES = \[(.*?)\] as const;/s', $source, $list) !== 1) {
            self::fail('COLUMN_TYPES literal not found');
        }

        preg_match_all("/'([a-z]+)'/", $list[1], $values);

        self::assertSame(array_column(SchemaColumnType::cases(), 'value'), $values[1]);
    }
}
