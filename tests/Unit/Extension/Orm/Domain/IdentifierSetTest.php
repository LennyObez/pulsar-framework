<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Orm\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Domain\IdentifierSet;
use Pulsar\Extension\Orm\Exception\QueryBuilderException;

#[CoversClass(IdentifierSet::class)]
final class IdentifierSetTest extends TestCase
{
    #[Test]
    public function ofCreatesSetFromValidIdentifiers(): void
    {
        $set = IdentifierSet::of(['id', 'name', 'email']);

        self::assertSame(['id', 'name', 'email'], $set->toArray());
        self::assertSame(3, $set->count());
    }

    #[Test]
    public function ofDeduplicatesIdentifiers(): void
    {
        $set = IdentifierSet::of(['id', 'name', 'id', 'email', 'name']);

        self::assertSame(['id', 'name', 'email'], $set->toArray());
        self::assertSame(3, $set->count());
    }

    #[Test]
    public function ofCreatesEmptySet(): void
    {
        $set = IdentifierSet::of([]);

        self::assertTrue($set->isEmpty());
        self::assertSame(0, $set->count());
        self::assertSame([], $set->toArray());
    }

    #[Test]
    public function containsReturnsTrueForExistingIdentifier(): void
    {
        $set = IdentifierSet::of(['id', 'name', 'email']);

        self::assertTrue($set->contains('name'));
    }

    #[Test]
    public function containsReturnsFalseForMissingIdentifier(): void
    {
        $set = IdentifierSet::of(['id', 'name', 'email']);

        self::assertFalse($set->contains('phone'));
    }

    #[Test]
    public function isEmptyReturnsFalseForNonEmptySet(): void
    {
        $set = IdentifierSet::of(['id']);

        self::assertFalse($set->isEmpty());
    }

    #[Test]
    public function ofRejectsInvalidIdentifier(): void
    {
        $this->expectException(QueryBuilderException::class);

        $_ = IdentifierSet::of(['id', 'bad-name']);
    }
}
