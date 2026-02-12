<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Domain\EntityId;

final class EntityIdTest extends TestCase
{
    #[Test]
    public function constructionWithIntId(): void
    {
        $id = new EntityId(entityClass: 'App\\Entity\\User', value: 42);

        self::assertSame('App\\Entity\\User', $id->entityClass);
        self::assertSame(42, $id->value);
    }

    #[Test]
    public function constructionWithStringId(): void
    {
        $id = new EntityId(entityClass: 'App\\Entity\\Order', value: 'abc-123');

        self::assertSame('App\\Entity\\Order', $id->entityClass);
        self::assertSame('abc-123', $id->value);
    }

    #[Test]
    public function toStringFormat(): void
    {
        $id = new EntityId(entityClass: 'App\\Entity\\User', value: 99);

        self::assertSame('App\\Entity\\User#99', $id->toString());
    }

    #[Test]
    public function equalsReturnsTrueForSameClassAndValue(): void
    {
        $a = new EntityId('App\\Entity\\User', 1);
        $b = new EntityId('App\\Entity\\User', 1);

        self::assertTrue($a->equals($b));
    }

    #[Test]
    public function equalsReturnsFalseForDifferentClass(): void
    {
        $a = new EntityId('App\\Entity\\User', 1);
        $b = new EntityId('App\\Entity\\Post', 1);

        self::assertFalse($a->equals($b));
    }

    #[Test]
    public function equalsReturnsFalseForDifferentValue(): void
    {
        $a = new EntityId('App\\Entity\\User', 1);
        $b = new EntityId('App\\Entity\\User', 2);

        self::assertFalse($a->equals($b));
    }

    #[Test]
    public function equalsDistinguishesIntFromString(): void
    {
        $a = new EntityId('App\\Entity\\User', 1);
        $b = new EntityId('App\\Entity\\User', '1');

        self::assertFalse($a->equals($b));
    }
}
