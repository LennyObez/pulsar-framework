<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Schema\Blueprint;
use Pulsar\Database\Schema\ColumnBuilder;
use Pulsar\Database\Schema\SchemaColumnType;
use Pulsar\Database\Schema\SchemaDefaultExpression;

#[CoversClass(ColumnBuilder::class)]
#[CoversClass(Blueprint::class)]
final class ColumnDefinitionTest extends TestCase
{
    #[Test]
    public function nullableReturnsSelfForChaining(): void
    {
        $blueprint = new Blueprint('test');
        $builder = $blueprint->string('name');
        $result = $builder->nullable();

        self::assertSame($builder, $result);
    }

    #[Test]
    public function defaultReturnsSelfForChaining(): void
    {
        $blueprint = new Blueprint('test');
        $builder = $blueprint->string('name');
        $result = $builder->default('foo');

        self::assertSame($builder, $result);
    }

    #[Test]
    public function uniqueReturnsSelfForChaining(): void
    {
        $blueprint = new Blueprint('test');
        $builder = $blueprint->string('name');
        $result = $builder->unique();

        self::assertSame($builder, $result);
    }

    #[Test]
    public function unsignedReturnsSelfForChaining(): void
    {
        $blueprint = new Blueprint('test');
        $builder = $blueprint->integer('count');
        $result = $builder->unsigned();

        self::assertSame($builder, $result);
    }

    #[Test]
    public function autoIncrementReturnsSelfForChaining(): void
    {
        $blueprint = new Blueprint('test');
        $builder = $blueprint->integer('seq');
        $result = $builder->autoIncrement();

        self::assertSame($builder, $result);
    }

    #[Test]
    public function commentReturnsSelfForChaining(): void
    {
        $blueprint = new Blueprint('test');
        $builder = $blueprint->string('name');
        $result = $builder->comment('User full name');

        self::assertSame($builder, $result);
    }

    #[Test]
    public function nullableSetsColumnToNullable(): void
    {
        $blueprint = new Blueprint('test');
        $blueprint->string('bio')->nullable();
        $def = $blueprint->toDefinition();

        self::assertTrue($def->columns[0]->nullable);
    }

    #[Test]
    public function nullableFalseKeepsColumnNotNull(): void
    {
        $blueprint = new Blueprint('test');
        $blueprint->string('name')->nullable(false);
        $def = $blueprint->toDefinition();

        self::assertFalse($def->columns[0]->nullable);
    }

    #[Test]
    public function defaultSetsScalarValue(): void
    {
        $blueprint = new Blueprint('test');
        $blueprint->integer('count')->default(0);
        $def = $blueprint->toDefinition();

        self::assertTrue($def->columns[0]->hasDefault);
        self::assertSame(0, $def->columns[0]->default);
    }

    #[Test]
    public function defaultNullSetsNullDefault(): void
    {
        $blueprint = new Blueprint('test');
        $blueprint->string('note')->nullable()->default(null);
        $def = $blueprint->toDefinition();

        self::assertTrue($def->columns[0]->hasDefault);
        self::assertNull($def->columns[0]->default);
    }

    #[Test]
    public function defaultStringValue(): void
    {
        $blueprint = new Blueprint('test');
        $blueprint->string('role')->default('user');
        $def = $blueprint->toDefinition();

        self::assertSame('user', $def->columns[0]->default);
    }

    #[Test]
    public function defaultBooleanValue(): void
    {
        $blueprint = new Blueprint('test');
        $blueprint->boolean('active')->default(true);
        $def = $blueprint->toDefinition();

        self::assertTrue($def->columns[0]->default);
    }

    #[Test]
    public function defaultFloatValue(): void
    {
        $blueprint = new Blueprint('test');
        $blueprint->decimal('rate', 5, 2)->default(1.5);
        $def = $blueprint->toDefinition();

        self::assertSame(1.5, $def->columns[0]->default);
    }

    #[Test]
    public function unsignedSetsUnsignedFlag(): void
    {
        $blueprint = new Blueprint('test');
        $blueprint->integer('age')->unsigned();
        $def = $blueprint->toDefinition();

        self::assertTrue($def->columns[0]->unsigned);
    }

    #[Test]
    public function autoIncrementSetsAutoIncrementFlag(): void
    {
        $blueprint = new Blueprint('test');
        $blueprint->integer('seq')->autoIncrement();
        $def = $blueprint->toDefinition();

        self::assertTrue($def->columns[0]->autoIncrement);
    }

    #[Test]
    public function commentSetsCommentText(): void
    {
        $blueprint = new Blueprint('test');
        $blueprint->string('name')->comment('The user display name');
        $def = $blueprint->toDefinition();

        self::assertSame('The user display name', $def->columns[0]->comment);
    }

    #[Test]
    public function commentDefaultsToNull(): void
    {
        $blueprint = new Blueprint('test');
        $blueprint->string('name');
        $def = $blueprint->toDefinition();

        self::assertNull($def->columns[0]->comment);
    }

    #[Test]
    public function defaultExpressionSetsExpression(): void
    {
        $blueprint = new Blueprint('test');
        $blueprint->timestamp('created_at')->defaultExpression(SchemaDefaultExpression::CurrentTimestamp);
        $def = $blueprint->toDefinition();

        self::assertSame(SchemaDefaultExpression::CurrentTimestamp, $def->columns[0]->defaultExpression);
    }

    #[Test]
    public function chainingMultipleModifiers(): void
    {
        $blueprint = new Blueprint('test');
        $blueprint->integer('score')
            ->unsigned()
            ->nullable()
            ->default(0)
            ->comment('Player score');
        $def = $blueprint->toDefinition();

        $col = $def->columns[0];
        self::assertTrue($col->unsigned);
        self::assertTrue($col->nullable);
        self::assertTrue($col->hasDefault);
        self::assertSame(0, $col->default);
        self::assertSame('Player score', $col->comment);
    }

    /**
     * @return iterable<string, array{string, SchemaColumnType}>
     */
    public static function columnTypeProvider(): iterable
    {
        yield 'string' => ['string', SchemaColumnType::String];
        yield 'text' => ['text', SchemaColumnType::Text];
        yield 'integer' => ['integer', SchemaColumnType::Integer];
        yield 'bigInteger' => ['bigInteger', SchemaColumnType::BigInt];
        yield 'smallInteger' => ['smallInteger', SchemaColumnType::SmallInt];
        yield 'boolean' => ['boolean', SchemaColumnType::Boolean];
        yield 'json' => ['json', SchemaColumnType::Json];
        yield 'binary' => ['binary', SchemaColumnType::Binary];
    }

    #[Test]
    #[DataProvider('columnTypeProvider')]
    public function blueprintMethodProducesCorrectColumnType(string $method, SchemaColumnType $expectedType): void
    {
        $blueprint = new Blueprint('test');
        $blueprint->{$method}('col');
        $def = $blueprint->toDefinition();

        self::assertSame($expectedType, $def->columns[0]->type);
    }

    #[Test]
    public function timestampProducesDateTimeType(): void
    {
        $blueprint = new Blueprint('test');
        $blueprint->timestamp('fired_at');
        $def = $blueprint->toDefinition();

        self::assertSame(SchemaColumnType::DateTime, $def->columns[0]->type);
    }

    #[Test]
    public function decimalStoresPrecisionAndScale(): void
    {
        $blueprint = new Blueprint('test');
        $blueprint->decimal('price', 10, 2);
        $def = $blueprint->toDefinition();

        $col = $def->columns[0];
        self::assertSame(SchemaColumnType::Decimal, $col->type);
        self::assertSame(10, $col->precision);
        self::assertSame(2, $col->scale);
    }

    #[Test]
    public function uuidProducesUuidType(): void
    {
        $blueprint = new Blueprint('test');
        $blueprint->uuid('token');
        $def = $blueprint->toDefinition();

        self::assertSame(SchemaColumnType::Uuid, $def->columns[0]->type);
    }

    #[Test]
    public function columnsAreNotNullableByDefault(): void
    {
        $blueprint = new Blueprint('test');
        $blueprint->string('name');
        $def = $blueprint->toDefinition();

        self::assertFalse($def->columns[0]->nullable);
    }

    #[Test]
    public function columnsAreNotUnsignedByDefault(): void
    {
        $blueprint = new Blueprint('test');
        $blueprint->integer('count');
        $def = $blueprint->toDefinition();

        self::assertFalse($def->columns[0]->unsigned);
    }

    #[Test]
    public function columnsDoNotAutoIncrementByDefault(): void
    {
        $blueprint = new Blueprint('test');
        $blueprint->integer('count');
        $def = $blueprint->toDefinition();

        self::assertFalse($def->columns[0]->autoIncrement);
    }

    #[Test]
    public function columnsHaveNoDefaultByDefault(): void
    {
        $blueprint = new Blueprint('test');
        $blueprint->string('name');
        $def = $blueprint->toDefinition();

        self::assertFalse($def->columns[0]->hasDefault);
    }
}
