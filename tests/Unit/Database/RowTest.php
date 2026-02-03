<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Exception\DatabaseException;
use Pulsar\Database\Row;

#[CoversClass(Row::class)]
final class RowTest extends TestCase
{
    #[Test]
    public function getReturnsColumnValue(): void
    {
        $row = new Row(['name' => 'Alice', 'age' => 30]);

        self::assertSame('Alice', $row->get('name'));
        self::assertSame(30, $row->get('age'));
    }

    #[Test]
    public function getThrowsOnMissingColumn(): void
    {
        $row = new Row(['name' => 'Alice']);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Column "missing" not found in row');

        $_ = $row->get('missing');
    }

    #[Test]
    public function getOrDefaultReturnsFallbackForMissingColumn(): void
    {
        $row = new Row(['name' => 'Alice']);

        self::assertSame('default', $row->getOrDefault('missing', 'default'));
        self::assertNull($row->getOrDefault('missing'));
    }

    #[Test]
    public function getOrDefaultReturnsActualValueWhenPresent(): void
    {
        $row = new Row(['name' => 'Alice']);

        self::assertSame('Alice', $row->getOrDefault('name', 'default'));
    }

    #[Test]
    public function getIntReturnsIntegerValue(): void
    {
        $row = new Row(['count' => 42]);

        self::assertSame(42, $row->getInt('count'));
    }

    #[Test]
    public function getIntCastsStringToInt(): void
    {
        $row = new Row(['count' => '42']);

        self::assertSame(42, $row->getInt('count'));
    }

    #[Test]
    public function getIntCastsNegativeStringToInt(): void
    {
        $row = new Row(['count' => '-5']);

        self::assertSame(-5, $row->getInt('count'));
    }

    #[Test]
    public function getIntThrowsOnNonNumericString(): void
    {
        $row = new Row(['value' => 'abc']);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Cannot cast column "value" to int');

        $row->getInt('value');
    }

    #[Test]
    public function getStringReturnsStringValue(): void
    {
        $row = new Row(['name' => 'Alice']);

        self::assertSame('Alice', $row->getString('name'));
    }

    #[Test]
    public function getStringCastsIntToString(): void
    {
        $row = new Row(['id' => 42]);

        self::assertSame('42', $row->getString('id'));
    }

    #[Test]
    public function getStringCastsFloatToString(): void
    {
        $row = new Row(['price' => 9.99]);

        self::assertSame('9.99', $row->getString('price'));
    }

    #[Test]
    public function getStringThrowsOnNull(): void
    {
        $row = new Row(['value' => null]);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Cannot cast column "value" to string');

        $row->getString('value');
    }

    #[Test]
    public function getBoolReturnsTrueForBoolTrue(): void
    {
        $row = new Row(['active' => true]);

        self::assertTrue($row->getBool('active'));
    }

    #[Test]
    public function getBoolReturnsFalseForBoolFalse(): void
    {
        $row = new Row(['active' => false]);

        self::assertFalse($row->getBool('active'));
    }

    #[Test]
    public function getBoolReturnsTrueForIntOne(): void
    {
        $row = new Row(['active' => 1]);

        self::assertTrue($row->getBool('active'));
    }

    #[Test]
    public function getBoolReturnsFalseForIntZero(): void
    {
        $row = new Row(['active' => 0]);

        self::assertFalse($row->getBool('active'));
    }

    #[Test]
    public function getBoolReturnsTrueForStringOne(): void
    {
        $row = new Row(['active' => '1']);

        self::assertTrue($row->getBool('active'));
    }

    #[Test]
    public function getBoolReturnsFalseForStringZero(): void
    {
        $row = new Row(['active' => '0']);

        self::assertFalse($row->getBool('active'));
    }

    #[Test]
    public function getBoolThrowsOnInvalidValue(): void
    {
        $row = new Row(['active' => 'yes']);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Cannot cast column "active" to bool');

        $row->getBool('active');
    }

    #[Test]
    public function getFloatReturnsFloatValue(): void
    {
        $row = new Row(['price' => 9.99]);

        self::assertSame(9.99, $row->getFloat('price'));
    }

    #[Test]
    public function getFloatCastsIntToFloat(): void
    {
        $row = new Row(['price' => 10]);

        self::assertSame(10.0, $row->getFloat('price'));
    }

    #[Test]
    public function getFloatCastsNumericStringToFloat(): void
    {
        $row = new Row(['price' => '9.99']);

        self::assertSame(9.99, $row->getFloat('price'));
    }

    #[Test]
    public function getFloatThrowsOnNonNumericString(): void
    {
        $row = new Row(['price' => 'abc']);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Cannot cast column "price" to float');

        $row->getFloat('price');
    }

    #[Test]
    public function getNullableIntReturnsNullForNull(): void
    {
        $row = new Row(['count' => null]);

        self::assertNull($row->getNullableInt('count'));
    }

    #[Test]
    public function getNullableIntReturnsIntForIntValue(): void
    {
        $row = new Row(['count' => 42]);

        self::assertSame(42, $row->getNullableInt('count'));
    }

    #[Test]
    public function getNullableStringReturnsNullForNull(): void
    {
        $row = new Row(['name' => null]);

        self::assertNull($row->getNullableString('name'));
    }

    #[Test]
    public function getNullableStringReturnsStringForStringValue(): void
    {
        $row = new Row(['name' => 'Alice']);

        self::assertSame('Alice', $row->getNullableString('name'));
    }

    #[Test]
    public function hasReturnsTrueForExistingColumn(): void
    {
        $row = new Row(['name' => 'Alice']);

        self::assertTrue($row->has('name'));
    }

    #[Test]
    public function hasReturnsFalseForMissingColumn(): void
    {
        $row = new Row(['name' => 'Alice']);

        self::assertFalse($row->has('missing'));
    }

    #[Test]
    public function hasReturnsTrueForNullValueColumn(): void
    {
        $row = new Row(['name' => null]);

        self::assertTrue($row->has('name'));
    }

    #[Test]
    public function columnsReturnsColumnNames(): void
    {
        $row = new Row(['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com']);

        self::assertSame(['id', 'name', 'email'], $row->columns());
    }

    #[Test]
    public function toArrayReturnsRawData(): void
    {
        $data = ['id' => 1, 'name' => 'Alice'];
        $row = new Row($data);

        self::assertSame($data, $row->toArray());
    }

    #[Test]
    public function getIntHandlesZeroString(): void
    {
        $row = new Row(['count' => '0']);

        self::assertSame(0, $row->getInt('count'));
    }

    #[Test]
    public function getNullableIntCastsStringToInt(): void
    {
        $row = new Row(['count' => '42']);

        self::assertSame(42, $row->getNullableInt('count'));
    }

    #[Test]
    public function getNullableIntThrowsOnInvalidValue(): void
    {
        $row = new Row(['count' => 'abc']);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Cannot cast column "count" to int');

        $row->getNullableInt('count');
    }

    #[Test]
    public function getNullableStringCastsIntToString(): void
    {
        $row = new Row(['id' => 42]);

        self::assertSame('42', $row->getNullableString('id'));
    }

    #[Test]
    public function getNullableStringCastsFloatToString(): void
    {
        $row = new Row(['price' => 9.99]);

        self::assertSame('9.99', $row->getNullableString('price'));
    }

    #[Test]
    public function getNullableStringThrowsOnInvalidValue(): void
    {
        $row = new Row(['data' => []]);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Cannot cast column "data" to string');

        $row->getNullableString('data');
    }

    #[Test]
    public function getFloatThrowsOnNull(): void
    {
        $row = new Row(['value' => null]);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Cannot cast column "value" to float');

        $row->getFloat('value');
    }

    #[Test]
    public function getIntThrowsOnEmptyString(): void
    {
        $row = new Row(['value' => '']);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Cannot cast column "value" to int');

        $row->getInt('value');
    }
}
