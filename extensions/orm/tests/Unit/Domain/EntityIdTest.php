<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Domain\EntityId;
use Pulsar\Extension\Orm\Tests\Unit\Fixtures\OrderEntity;
use Pulsar\Extension\Orm\Tests\Unit\Fixtures\PostEntity;
use Pulsar\Extension\Orm\Tests\Unit\Fixtures\UserEntity;

final class EntityIdTest extends TestCase
{
    #[Test]
    public function constructionWithIntId(): void
    {
        $id = new EntityId(entityClass: UserEntity::class, value: 42);

        self::assertSame(UserEntity::class, $id->entityClass);
        self::assertSame(42, $id->value);
    }

    #[Test]
    public function constructionWithStringId(): void
    {
        $id = new EntityId(entityClass: OrderEntity::class, value: 'abc-123');

        self::assertSame(OrderEntity::class, $id->entityClass);
        self::assertSame('abc-123', $id->value);
    }

    #[Test]
    public function toStringFormat(): void
    {
        $id = new EntityId(entityClass: UserEntity::class, value: 99);

        self::assertSame(UserEntity::class . '#99', $id->toString());
    }

    #[Test]
    public function equalsReturnsTrueForSameClassAndValue(): void
    {
        $a = new EntityId(UserEntity::class, 1);
        $b = new EntityId(UserEntity::class, 1);

        self::assertTrue($a->equals($b));
    }

    #[Test]
    public function equalsReturnsFalseForDifferentClass(): void
    {
        $a = new EntityId(UserEntity::class, 1);
        $b = new EntityId(PostEntity::class, 1);

        self::assertFalse($a->equals($b));
    }

    #[Test]
    public function equalsReturnsFalseForDifferentValue(): void
    {
        $a = new EntityId(UserEntity::class, 1);
        $b = new EntityId(UserEntity::class, 2);

        self::assertFalse($a->equals($b));
    }

    #[Test]
    public function equalsDistinguishesIntFromString(): void
    {
        $a = new EntityId(UserEntity::class, 1);
        $b = new EntityId(UserEntity::class, '1');

        self::assertFalse($a->equals($b));
    }
}
