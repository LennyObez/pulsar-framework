<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Features\Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Result;
use Pulsar\Extension\Orm\Features\Query\InsertBuilder;

final class InsertBuilderReturningTest extends TestCase
{
    #[Test]
    public function executeReadsReturnedIdOnReturningDialects(): void
    {
        // FR-31: on a dialect that supports RETURNING, the inserted primary key
        // is read from the statement's result set — authoritative even when an
        // insert trigger advances another sequence, which is exactly the case
        // where lastInsertId() returns the wrong value on PostgreSQL.
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::PostgreSQL);
        $connection->method('query')->willReturn(Result::fromArrays([['id' => 42]]));
        $connection->method('lastInsertId')->willReturn('999'); // wrong: a trigger-touched sequence

        $builder = new InsertBuilder($connection, 'items', 'id');
        $id = $builder->values(['name' => 'Widget'])->execute();

        self::assertSame('42', $id, 'the id must come from RETURNING, not lastInsertId()');
    }

    #[Test]
    public function executeFallsBackToLastInsertIdOnNonReturningDialects(): void
    {
        // MySQL has no RETURNING; the builder must still return the generated id
        // via lastInsertId() (a single auto-increment, no trigger ambiguity).
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->method('execute')->willReturn(1);
        $connection->method('lastInsertId')->willReturn('77');

        $builder = new InsertBuilder($connection, 'items', 'id');

        self::assertSame('77', $builder->values(['name' => 'Widget'])->execute());
    }
}
