<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Features\Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Result;
use Pulsar\Extension\Orm\Features\Query\SelectBuilder;
use Pulsar\Pagination\CursorPaginator;

use function str_contains;

/**
 * The pagination cursor is opaque but fully client-controlled (base64 of
 * JSON). Only the cursor VALUE may be trusted (it is bound as a parameter);
 * the column it names must never be allowed to steer the WHERE/ORDER BY.
 */
final class SelectBuilderCursorSecurityTest extends TestCase
{
    /**
     * @return array{0: SelectBuilder, 1: callable(): ?string}
     */
    private function builderCapturingSql(): array
    {
        $captured = null;
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->method('query')->willReturnCallback(
            function (string $sql) use (&$captured): Result {
                $captured = $sql;

                return new Result([]);
            },
        );

        $builder = new SelectBuilder($connection);
        $builder->from('accounts', 't0');

        return [$builder, function () use (&$captured): ?string {
            return $captured;
        }];
    }

    #[Test]
    public function tamperedCursorColumnNeverReachesTheSql(): void
    {
        [$builder, $sql] = $this->builderCapturingSql();

        // Attacker crafts a cursor naming a column other than the one the
        // query pages by, carrying a backtick-breakout payload.
        $maliciousColumn = 'id`,(SELECT secret FROM vault))-- ';
        $tampered = CursorPaginator::encodeCursor($maliciousColumn, '1');

        $builder->cursorPaginate(perPage: 10, cursor: $tampered, cursorColumn: 'id');

        $rendered = $sql();
        self::assertNotNull($rendered);
        // The attacker's column name must appear nowhere in the SQL, and
        // because its column != the configured 'id' the WHERE filter is
        // dropped entirely rather than executed on an arbitrary column.
        self::assertStringNotContainsString('SELECT secret FROM vault', $rendered);
        self::assertFalse(str_contains($rendered, 'vault'));
    }

    #[Test]
    public function legitimateCursorAppliesTheKeysetFilterOnTheConfiguredColumn(): void
    {
        [$builder, $sql] = $this->builderCapturingSql();

        // A cursor produced for the same column the query pages by is honoured.
        $legit = CursorPaginator::encodeCursor('id', '42');

        $builder->cursorPaginate(perPage: 10, cursor: $legit, cursorColumn: 'id');

        $rendered = $sql();
        self::assertNotNull($rendered);
        // Keyset predicate present, ordering present, on the quoted id column.
        self::assertStringContainsString('`t0`.`id` >', $rendered);
        self::assertStringContainsString('ORDER BY', $rendered);
    }
}
