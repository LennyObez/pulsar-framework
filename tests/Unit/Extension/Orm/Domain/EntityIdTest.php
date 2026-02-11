<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Orm\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Domain\EntityId;
use stdClass;

#[CoversClass(EntityId::class)]
final class EntityIdTest extends TestCase
{
    #[Test]
    public function constructWithIntValue(): void
    {
        $id = new EntityId(stdClass::class, 42);

        self::assertSame(stdClass::class, $id->entityClass);
        self::assertSame(42, $id->value);
    }

    #[Test]
    public function constructWithStringValue(): void
    {
        $id = new EntityId(DateTimeImmutable::class, 'uuid-123');

        self::assertSame(DateTimeImmutable::class, $id->entityClass);
        self::assertSame('uuid-123', $id->value);
    }

    #[Test]
    public function toStringFormatsCorrectly(): void
    {
        $id = new EntityId(stdClass::class, 42);

        self::assertSame(stdClass::class . '#42', $id->toString());
    }

    #[Test]
    public function toStringWithStringValue(): void
    {
        $id = new EntityId(DateTimeImmutable::class, 'abc');

        self::assertSame(DateTimeImmutable::class . '#abc', $id->toString());
    }

    #[Test]
    public function equalsSameEntityAndValue(): void
    {
        $a = new EntityId(stdClass::class, 42);
        $b = new EntityId(stdClass::class, 42);

        self::assertTrue($a->equals($b));
    }

    #[Test]
    public function equalsDifferentEntity(): void
    {
        $a = new EntityId(stdClass::class, 42);
        $b = new EntityId(DateTimeImmutable::class, 42);

        self::assertFalse($a->equals($b));
    }

    #[Test]
    public function equalsDifferentValue(): void
    {
        $a = new EntityId(stdClass::class, 42);
        $b = new EntityId(stdClass::class, 99);

        self::assertFalse($a->equals($b));
    }

    #[Test]
    public function equalsDistinguishesIntFromString(): void
    {
        $a = new EntityId(stdClass::class, 42);
        $b = new EntityId(stdClass::class, '42');

        self::assertFalse($a->equals($b));
    }
}
