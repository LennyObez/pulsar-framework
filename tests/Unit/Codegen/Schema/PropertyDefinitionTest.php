<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Codegen\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Codegen\Schema\PropertyDefinition;

#[CoversClass(PropertyDefinition::class)]
final class PropertyDefinitionTest extends TestCase
{
    #[Test]
    public function constructWithAllProperties(): void
    {
        $prop = new PropertyDefinition(
            name: 'userName',
            phpType: 'string',
            columnName: 'user_name',
            columnType: 'varchar(255)',
            nullable: false,
            hasDefault: false,
            defaultValue: null,
            validationRules: ['required', 'max:255'],
            isFilterable: true,
            isSortable: true,
            length: 255,
            isPrimaryKey: false,
        );

        self::assertSame('userName', $prop->name);
        self::assertSame('string', $prop->phpType);
        self::assertSame('user_name', $prop->columnName);
        self::assertSame('varchar(255)', $prop->columnType);
        self::assertFalse($prop->nullable);
        self::assertFalse($prop->hasDefault);
        self::assertNull($prop->defaultValue);
        self::assertSame(['required', 'max:255'], $prop->validationRules);
        self::assertTrue($prop->isFilterable);
        self::assertTrue($prop->isSortable);
        self::assertSame(255, $prop->length);
        self::assertFalse($prop->isPrimaryKey);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function columnTypeToPhpProvider(): iterable
    {
        yield 'varchar' => ['varchar(255)', 'string'];
        yield 'varchar bare' => ['varchar', 'string'];
        yield 'text' => ['text', 'string'];
        yield 'mediumtext' => ['mediumtext', 'string'];
        yield 'longtext' => ['longtext', 'string'];
        yield 'char' => ['char(36)', 'string'];
        yield 'uuid' => ['uuid', 'string'];
        yield 'string' => ['string', 'string'];
        yield 'character varying' => ['character varying', 'string'];
        yield 'int' => ['int', 'int'];
        yield 'integer' => ['integer', 'int'];
        yield 'bigint' => ['bigint', 'int'];
        yield 'smallint' => ['smallint', 'int'];
        yield 'mediumint' => ['mediumint', 'int'];
        yield 'tinyint' => ['tinyint', 'int'];
        yield 'decimal' => ['decimal', 'float'];
        yield 'float' => ['float', 'float'];
        yield 'double' => ['double', 'float'];
        yield 'real' => ['real', 'float'];
        yield 'numeric' => ['numeric', 'float'];
        yield 'bool' => ['bool', 'bool'];
        yield 'boolean' => ['boolean', 'bool'];
        yield 'tinyint(1)' => ['tinyint(1)', 'bool'];
        yield 'datetime' => ['datetime', '\\DateTimeImmutable'];
        yield 'timestamp' => ['timestamp', '\\DateTimeImmutable'];
        yield 'timestamp with time zone' => ['timestamp with time zone', '\\DateTimeImmutable'];
        yield 'date' => ['date', '\\DateTimeImmutable'];
        yield 'json' => ['json', 'array'];
        yield 'jsonb' => ['jsonb', 'array'];
        yield 'blob' => ['blob', 'string'];
        yield 'binary' => ['binary', 'string'];
        yield 'unknown' => ['geometry', 'mixed'];
    }

    #[Test]
    #[DataProvider('columnTypeToPhpProvider')]
    public function mapColumnTypeToPhpReturnsCorrectType(string $columnType, string $expectedPhpType): void
    {
        self::assertSame($expectedPhpType, PropertyDefinition::mapColumnTypeToPhp($columnType));
    }

    #[Test]
    public function mapColumnTypeToPhpIsCaseInsensitive(): void
    {
        self::assertSame('string', PropertyDefinition::mapColumnTypeToPhp('VARCHAR(100)'));
        self::assertSame('int', PropertyDefinition::mapColumnTypeToPhp('INT'));
        self::assertSame('bool', PropertyDefinition::mapColumnTypeToPhp('BOOLEAN'));
    }

    #[Test]
    public function extractLengthFromVarchar(): void
    {
        self::assertSame(255, PropertyDefinition::extractLength('varchar(255)'));
    }

    #[Test]
    public function extractLengthFromChar(): void
    {
        self::assertSame(36, PropertyDefinition::extractLength('char(36)'));
    }

    #[Test]
    public function extractLengthReturnsNullForTypesWithoutLength(): void
    {
        self::assertNull(PropertyDefinition::extractLength('text'));
        self::assertNull(PropertyDefinition::extractLength('int'));
    }

    #[Test]
    public function extractLengthReturnsNullForTinyintOne(): void
    {
        self::assertNull(PropertyDefinition::extractLength('tinyint(1)'));
    }

    #[Test]
    public function inferValidationRulesForRequiredString(): void
    {
        $rules = PropertyDefinition::inferValidationRules('varchar(100)', false, 100);

        self::assertContains('required', $rules);
        self::assertContains('max:100', $rules);
    }

    #[Test]
    public function inferValidationRulesForNullableString(): void
    {
        $rules = PropertyDefinition::inferValidationRules('varchar(100)', true, 100);

        self::assertNotContains('required', $rules);
        self::assertContains('max:100', $rules);
    }

    #[Test]
    public function inferValidationRulesForInteger(): void
    {
        $rules = PropertyDefinition::inferValidationRules('int', false, null);

        self::assertContains('required', $rules);
        self::assertContains('integer', $rules);
    }

    #[Test]
    public function inferValidationRulesForFloat(): void
    {
        $rules = PropertyDefinition::inferValidationRules('decimal', false, null);

        self::assertContains('numeric', $rules);
    }

    #[Test]
    public function toArrayProducesExpectedStructure(): void
    {
        $prop = new PropertyDefinition(
            name: 'id',
            phpType: 'int',
            columnName: 'id',
            columnType: 'bigint',
            nullable: false,
            hasDefault: true,
            defaultValue: null,
            validationRules: ['required', 'integer'],
            isFilterable: false,
            isSortable: true,
            length: null,
            isPrimaryKey: true,
        );

        $array = $prop->toArray();

        self::assertSame('id', $array['name']);
        self::assertSame('int', $array['phpType']);
        self::assertSame('id', $array['columnName']);
        self::assertSame('bigint', $array['columnType']);
        self::assertFalse($array['nullable']);
        self::assertTrue($array['hasDefault']);
        self::assertNull($array['defaultValue']);
        self::assertSame(['required', 'integer'], $array['validationRules']);
        self::assertFalse($array['isFilterable']);
        self::assertTrue($array['isSortable']);
        self::assertNull($array['length']);
        self::assertTrue($array['isPrimaryKey']);
    }

    #[Test]
    public function fromArrayRoundTrips(): void
    {
        $original = new PropertyDefinition(
            name: 'email',
            phpType: 'string',
            columnName: 'email_address',
            columnType: 'varchar(200)',
            nullable: true,
            hasDefault: true,
            defaultValue: 'test@example.com',
            validationRules: ['max:200'],
            isFilterable: true,
            isSortable: false,
            length: 200,
            isPrimaryKey: false,
        );

        $restored = PropertyDefinition::fromArray($original->toArray());

        self::assertSame($original->name, $restored->name);
        self::assertSame($original->phpType, $restored->phpType);
        self::assertSame($original->columnName, $restored->columnName);
        self::assertSame($original->columnType, $restored->columnType);
        self::assertSame($original->nullable, $restored->nullable);
        self::assertSame($original->hasDefault, $restored->hasDefault);
        self::assertSame($original->defaultValue, $restored->defaultValue);
        self::assertSame($original->validationRules, $restored->validationRules);
        self::assertSame($original->isFilterable, $restored->isFilterable);
        self::assertSame($original->isSortable, $restored->isSortable);
        self::assertSame($original->length, $restored->length);
        self::assertSame($original->isPrimaryKey, $restored->isPrimaryKey);
    }

    #[Test]
    public function fromArrayWithMissingKeysUsesDefaults(): void
    {
        $prop = PropertyDefinition::fromArray([]);

        self::assertSame('', $prop->name);
        self::assertSame('mixed', $prop->phpType);
        self::assertFalse($prop->nullable);
        self::assertFalse($prop->hasDefault);
        self::assertNull($prop->defaultValue);
        self::assertSame([], $prop->validationRules);
        self::assertFalse($prop->isFilterable);
        self::assertFalse($prop->isSortable);
        self::assertNull($prop->length);
        self::assertFalse($prop->isPrimaryKey);
    }
}
