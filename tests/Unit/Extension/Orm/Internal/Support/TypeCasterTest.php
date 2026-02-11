<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Orm\Internal\Support;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Domain\ColumnType;
use Pulsar\Extension\Orm\Internal\Support\TypeCaster;

#[CoversClass(TypeCaster::class)]
final class TypeCasterTest extends TestCase
{
    private TypeCaster $caster;

    protected function setUp(): void
    {
        $this->caster = new TypeCaster();
    }

    #[Test]
    public function fromDatabaseReturnsNullForNull(): void
    {
        self::assertNull($this->caster->fromDatabase(null, ColumnType::String));
        self::assertNull($this->caster->fromDatabase(null, ColumnType::Integer));
    }

    #[Test]
    public function fromDatabaseCastsStringType(): void
    {
        self::assertSame('hello', $this->caster->fromDatabase('hello', ColumnType::String));
        self::assertSame('42', $this->caster->fromDatabase(42, ColumnType::String));
    }

    #[Test]
    public function fromDatabaseCastsIntegerType(): void
    {
        self::assertSame(42, $this->caster->fromDatabase(42, ColumnType::Integer));
        self::assertSame(42, $this->caster->fromDatabase('42', ColumnType::Integer));
    }

    #[Test]
    public function fromDatabaseCastsSmallIntType(): void
    {
        self::assertSame(5, $this->caster->fromDatabase(5, ColumnType::SmallInt));
        self::assertSame(5, $this->caster->fromDatabase('5', ColumnType::SmallInt));
    }

    #[Test]
    public function fromDatabaseCastsBigIntAsString(): void
    {
        self::assertSame('9223372036854775807', $this->caster->fromDatabase('9223372036854775807', ColumnType::BigInt));
        self::assertSame('42', $this->caster->fromDatabase(42, ColumnType::BigInt));
    }

    #[Test]
    public function fromDatabaseCastsFloatType(): void
    {
        self::assertSame(3.14, $this->caster->fromDatabase(3.14, ColumnType::Float));
        self::assertSame(3.14, $this->caster->fromDatabase('3.14', ColumnType::Float));
    }

    #[Test]
    public function fromDatabaseCastsDecimalType(): void
    {
        self::assertSame(9.99, $this->caster->fromDatabase('9.99', ColumnType::Decimal));
    }

    #[Test]
    public function fromDatabaseCastsBooleanType(): void
    {
        self::assertTrue($this->caster->fromDatabase(true, ColumnType::Boolean));
        self::assertFalse($this->caster->fromDatabase(false, ColumnType::Boolean));
        self::assertTrue($this->caster->fromDatabase(1, ColumnType::Boolean));
        self::assertFalse($this->caster->fromDatabase(0, ColumnType::Boolean));
    }

    #[Test]
    public function fromDatabaseCastsDateTimeType(): void
    {
        $result = $this->caster->fromDatabase('2024-01-15 10:30:00', ColumnType::DateTime);

        self::assertInstanceOf(DateTimeImmutable::class, $result);
        self::assertSame('2024-01-15 10:30:00', $result->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function fromDatabasePassesThroughDateTimeImmutable(): void
    {
        $dt = new DateTimeImmutable('2024-01-15');
        $result = $this->caster->fromDatabase($dt, ColumnType::DateTime);

        self::assertSame($dt, $result);
    }

    #[Test]
    public function fromDatabaseCastsDateType(): void
    {
        $result = $this->caster->fromDatabase('2024-01-15', ColumnType::Date);

        self::assertInstanceOf(DateTimeImmutable::class, $result);
        self::assertSame('2024-01-15', $result->format('Y-m-d'));
    }

    #[Test]
    public function fromDatabaseCastsTimeType(): void
    {
        self::assertSame('10:30:00', $this->caster->fromDatabase('10:30:00', ColumnType::Time));
    }

    #[Test]
    public function fromDatabaseCastsJsonType(): void
    {
        $result = $this->caster->fromDatabase('{"key":"value"}', ColumnType::Json);

        self::assertSame(['key' => 'value'], $result);
    }

    #[Test]
    public function fromDatabasePassesThroughJsonArray(): void
    {
        $arr = ['key' => 'value'];
        $result = $this->caster->fromDatabase($arr, ColumnType::Json);

        self::assertSame($arr, $result);
    }

    #[Test]
    public function fromDatabaseCastsBinaryType(): void
    {
        self::assertSame('binary-data', $this->caster->fromDatabase('binary-data', ColumnType::Binary));
    }

    #[Test]
    public function fromDatabaseCastsUuidType(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        self::assertSame($uuid, $this->caster->fromDatabase($uuid, ColumnType::Uuid));
    }

    #[Test]
    public function fromDatabaseCastsEnumType(): void
    {
        self::assertSame('active', $this->caster->fromDatabase('active', ColumnType::Enum));
    }

    #[Test]
    public function toDatabaseReturnsNullForNull(): void
    {
        self::assertNull($this->caster->toDatabase(null, ColumnType::String));
        self::assertNull($this->caster->toDatabase(null, ColumnType::Integer));
    }

    #[Test]
    public function toDatabaseCastsStringType(): void
    {
        self::assertSame('hello', $this->caster->toDatabase('hello', ColumnType::String));
        self::assertSame('42', $this->caster->toDatabase(42, ColumnType::String));
    }

    #[Test]
    public function toDatabaseCastsIntegerType(): void
    {
        self::assertSame(42, $this->caster->toDatabase(42, ColumnType::Integer));
        self::assertSame(42, $this->caster->toDatabase('42', ColumnType::Integer));
    }

    #[Test]
    public function toDatabaseCastsBooleanToInt(): void
    {
        self::assertSame(1, $this->caster->toDatabase(true, ColumnType::Boolean));
        self::assertSame(0, $this->caster->toDatabase(false, ColumnType::Boolean));
    }

    #[Test]
    public function toDatabaseFormatsDateTime(): void
    {
        $dt = new DateTimeImmutable('2024-01-15 10:30:00');

        self::assertSame('2024-01-15 10:30:00', $this->caster->toDatabase($dt, ColumnType::DateTime));
    }

    #[Test]
    public function toDatabaseFormatsDate(): void
    {
        $dt = new DateTimeImmutable('2024-01-15');

        self::assertSame('2024-01-15', $this->caster->toDatabase($dt, ColumnType::Date));
    }

    #[Test]
    public function toDatabaseFormatsTime(): void
    {
        $dt = new DateTimeImmutable('2024-01-15 10:30:45');

        self::assertSame('10:30:45', $this->caster->toDatabase($dt, ColumnType::Time));
    }

    #[Test]
    public function toDatabaseEncodesJson(): void
    {
        $data = ['key' => 'value'];

        self::assertSame('{"key":"value"}', $this->caster->toDatabase($data, ColumnType::Json));
    }

    #[Test]
    public function toDatabasePassesThroughJsonString(): void
    {
        self::assertSame('{"a":1}', $this->caster->toDatabase('{"a":1}', ColumnType::Json));
    }

    #[Test]
    public function toDatabaseCastsBigIntToString(): void
    {
        self::assertSame('42', $this->caster->toDatabase(42, ColumnType::BigInt));
    }
}
