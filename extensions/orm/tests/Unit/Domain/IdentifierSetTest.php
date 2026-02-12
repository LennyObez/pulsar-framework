<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Domain\IdentifierSet;
use Pulsar\Extension\Orm\Exception\QueryBuilderException;

final class IdentifierSetTest extends TestCase
{
    #[Test]
    public function ofCreatesFromValidIdentifiers(): void
    {
        $set = IdentifierSet::of(['name', 'email', 'status']);

        self::assertSame(['name', 'email', 'status'], $set->toArray());
        self::assertSame(3, $set->count());
    }

    #[Test]
    public function deduplicatesIdentifiers(): void
    {
        $set = IdentifierSet::of(['name', 'email', 'name']);

        self::assertSame(['name', 'email'], $set->toArray());
        self::assertSame(2, $set->count());
    }

    #[Test]
    public function containsChecksPresence(): void
    {
        $set = IdentifierSet::of(['name', 'email']);

        self::assertTrue($set->contains('name'));
        self::assertFalse($set->contains('phone'));
    }

    #[Test]
    public function isEmptyForEmptySet(): void
    {
        $set = IdentifierSet::of([]);

        self::assertTrue($set->isEmpty());
        self::assertSame(0, $set->count());
    }

    #[Test]
    public function rejectsInvalidIdentifiers(): void
    {
        $this->expectException(QueryBuilderException::class);

        (void) IdentifierSet::of(['valid', 'invalid-id']);
    }
}
