<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Schema\SchemaDefaultExpression;
use ValueError;

#[CoversClass(SchemaDefaultExpression::class)]
final class SchemaDefaultExpressionTest extends TestCase
{
    #[Test]
    public function hasExpectedNumberOfCases(): void
    {
        self::assertCount(8, SchemaDefaultExpression::cases());
    }

    #[Test]
    #[DataProvider('expressionProvider')]
    public function backedValuesMatchExpectedSql(SchemaDefaultExpression $expression, string $expectedSql): void
    {
        self::assertSame($expectedSql, $expression->value);
    }

    /**
     * @return iterable<string, array{SchemaDefaultExpression, string}>
     */
    public static function expressionProvider(): iterable
    {
        yield 'CurrentTimestamp' => [SchemaDefaultExpression::CurrentTimestamp, 'CURRENT_TIMESTAMP'];
        yield 'CurrentDate' => [SchemaDefaultExpression::CurrentDate, 'CURRENT_DATE'];
        yield 'CurrentTime' => [SchemaDefaultExpression::CurrentTime, 'CURRENT_TIME'];
        yield 'True' => [SchemaDefaultExpression::True, 'TRUE'];
        yield 'False' => [SchemaDefaultExpression::False, 'FALSE'];
        yield 'Null' => [SchemaDefaultExpression::Null, 'NULL'];
        yield 'PostgresUuid' => [SchemaDefaultExpression::PostgresUuid, 'gen_random_uuid()'];
        yield 'MysqlUuid' => [SchemaDefaultExpression::MysqlUuid, '(UUID())'];
    }

    #[Test]
    #[DataProvider('fromStringProvider')]
    public function constructsFromBackedValue(string $value, SchemaDefaultExpression $expected): void
    {
        self::assertSame($expected, SchemaDefaultExpression::from($value));
    }

    /**
     * @return iterable<string, array{string, SchemaDefaultExpression}>
     */
    public static function fromStringProvider(): iterable
    {
        yield 'CURRENT_TIMESTAMP' => ['CURRENT_TIMESTAMP', SchemaDefaultExpression::CurrentTimestamp];
        yield 'NULL' => ['NULL', SchemaDefaultExpression::Null];
        yield 'gen_random_uuid()' => ['gen_random_uuid()', SchemaDefaultExpression::PostgresUuid];
    }

    #[Test]
    public function fromInvalidValueThrows(): void
    {
        $this->expectException(ValueError::class);

        SchemaDefaultExpression::from('NOW()');
    }

    #[Test]
    public function tryFromReturnsNullForInvalidValue(): void
    {
        self::assertNull(SchemaDefaultExpression::tryFrom('INVALID'));
    }

    #[Test]
    public function tryFromReturnsEnumForValidValue(): void
    {
        $result = SchemaDefaultExpression::tryFrom('TRUE');

        self::assertSame(SchemaDefaultExpression::True, $result);
    }
}
