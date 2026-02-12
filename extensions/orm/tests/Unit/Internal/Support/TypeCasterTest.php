<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Internal\Support;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Domain\ColumnType;
use Pulsar\Extension\Orm\Internal\Support\TypeCaster;

final class TypeCasterTest extends TestCase
{
    private TypeCaster $caster;

    protected function setUp(): void
    {
        $this->caster = new TypeCaster();
    }

    #[Test]
    public function fromDatabaseNullReturnsNull(): void
    {
        self::assertNull($this->caster->fromDatabase(null, ColumnType::String));
    }

    #[Test]
    public function fromDatabaseString(): void
    {
        self::assertSame('hello', $this->caster->fromDatabase('hello', ColumnType::String));
    }

    #[Test]
    public function fromDatabaseInteger(): void
    {
        self::assertSame(42, $this->caster->fromDatabase(42, ColumnType::Integer));
        self::assertSame(42, $this->caster->fromDatabase('42', ColumnType::Integer));
    }

    #[Test]
    public function fromDatabaseFloat(): void
    {
        self::assertSame(3.14, $this->caster->fromDatabase(3.14, ColumnType::Float));
        self::assertSame(3.14, $this->caster->fromDatabase('3.14', ColumnType::Float));
    }

    #[Test]
    public function fromDatabaseBoolean(): void
    {
        self::assertTrue($this->caster->fromDatabase(true, ColumnType::Boolean));
        self::assertFalse($this->caster->fromDatabase(false, ColumnType::Boolean));
        self::assertTrue($this->caster->fromDatabase(1, ColumnType::Boolean));
    }

    #[Test]
    public function fromDatabaseDateTime(): void
    {
        $result = $this->caster->fromDatabase('2024-01-15 10:30:00', ColumnType::DateTime);

        self::assertInstanceOf(DateTimeImmutable::class, $result);
        self::assertSame('2024-01-15', $result->format('Y-m-d'));
    }

    #[Test]
    public function fromDatabaseDateTimePreservesExisting(): void
    {
        $dt = new DateTimeImmutable('2024-06-15');
        $result = $this->caster->fromDatabase($dt, ColumnType::DateTime);

        self::assertSame($dt, $result);
    }

    #[Test]
    public function fromDatabaseJson(): void
    {
        $result = $this->caster->fromDatabase('{"key":"value"}', ColumnType::Json);

        self::assertSame(['key' => 'value'], $result);
    }

    #[Test]
    public function fromDatabaseJsonArray(): void
    {
        $data = ['key' => 'value'];
        $result = $this->caster->fromDatabase($data, ColumnType::Json);

        self::assertSame($data, $result);
    }

    #[Test]
    public function fromDatabaseDecimal(): void
    {
        self::assertSame(9.99, $this->caster->fromDatabase('9.99', ColumnType::Decimal));
    }

    #[Test]
    public function fromDatabaseSmallInt(): void
    {
        self::assertSame(5, $this->caster->fromDatabase(5, ColumnType::SmallInt));
    }

    #[Test]
    public function toDatabaseNullReturnsNull(): void
    {
        self::assertNull($this->caster->toDatabase(null, ColumnType::String));
    }

    #[Test]
    public function toDatabaseString(): void
    {
        self::assertSame('hello', $this->caster->toDatabase('hello', ColumnType::String));
    }

    #[Test]
    public function toDatabaseInteger(): void
    {
        self::assertSame(42, $this->caster->toDatabase(42, ColumnType::Integer));
    }

    #[Test]
    public function toDatabaseBooleanConvertsToInt(): void
    {
        self::assertSame(1, $this->caster->toDatabase(true, ColumnType::Boolean));
        self::assertSame(0, $this->caster->toDatabase(false, ColumnType::Boolean));
    }

    #[Test]
    public function toDatabaseDateTimeFormats(): void
    {
        $dt = new DateTimeImmutable('2024-03-15 14:30:00');

        self::assertSame('2024-03-15 14:30:00', $this->caster->toDatabase($dt, ColumnType::DateTime));
    }

    #[Test]
    public function toDatabaseDateFormats(): void
    {
        $dt = new DateTimeImmutable('2024-03-15');

        self::assertSame('2024-03-15', $this->caster->toDatabase($dt, ColumnType::Date));
    }

    #[Test]
    public function toDatabaseTimeFormats(): void
    {
        $dt = new DateTimeImmutable('2024-01-01 14:30:45');

        self::assertSame('14:30:45', $this->caster->toDatabase($dt, ColumnType::Time));
    }

    #[Test]
    public function toDatabaseJson(): void
    {
        self::assertSame('{"a":1}', $this->caster->toDatabase(['a' => 1], ColumnType::Json));
    }

    #[Test]
    public function toDatabaseJsonPassesThroughString(): void
    {
        self::assertSame('{"a":1}', $this->caster->toDatabase('{"a":1}', ColumnType::Json));
    }

    #[Test]
    public function toDatabaseDecimalConvertsToString(): void
    {
        $result = $this->caster->toDatabase(9.99, ColumnType::Decimal);

        self::assertIsString($result);
    }
}
