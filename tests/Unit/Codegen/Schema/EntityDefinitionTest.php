<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Codegen\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Codegen\Schema\EntityDefinition;
use Pulsar\Codegen\Schema\PropertyDefinition;
use Pulsar\Codegen\Schema\RelationshipDefinition;
use Pulsar\Codegen\Schema\RelationType;
use Pulsar\Database\Introspection\ColumnInfo;

#[CoversClass(EntityDefinition::class)]
final class EntityDefinitionTest extends TestCase
{
    #[Test]
    public function constructWithAllProperties(): void
    {
        $prop = new PropertyDefinition(
            name: 'id',
            phpType: 'int',
            columnName: 'id',
            columnType: 'bigint',
            nullable: false,
            hasDefault: true,
            defaultValue: null,
            validationRules: [],
            isFilterable: false,
            isSortable: true,
            length: null,
            isPrimaryKey: true,
        );

        $rel = new RelationshipDefinition(
            type: RelationType::HasMany,
            relatedEntity: 'Post',
            foreignKey: 'author_id',
            localKey: 'id',
        );

        $entity = new EntityDefinition(
            className: 'User',
            namespace: 'App\\Entity',
            tableName: 'users',
            properties: [$prop],
            relationships: [$rel],
            primaryKey: 'id',
            hasTimestamps: true,
            hasSoftDeletes: false,
            isAuditAware: true,
        );

        self::assertSame('User', $entity->className);
        self::assertSame('App\\Entity', $entity->namespace);
        self::assertSame('users', $entity->tableName);
        self::assertCount(1, $entity->properties);
        self::assertCount(1, $entity->relationships);
        self::assertSame('id', $entity->primaryKey);
        self::assertTrue($entity->hasTimestamps);
        self::assertFalse($entity->hasSoftDeletes);
        self::assertTrue($entity->isAuditAware);
    }

    #[Test]
    public function fromDatabaseColumnsCreatesEntity(): void
    {
        $columns = [
            new ColumnInfo('id', 'bigint', false, true, null),
            new ColumnInfo('name', 'varchar(255)', false, false, null),
            new ColumnInfo('email', 'varchar(200)', false, false, null),
            new ColumnInfo('created_at', 'timestamp', true, false, null),
            new ColumnInfo('updated_at', 'timestamp', true, false, null),
        ];

        $entity = EntityDefinition::fromDatabaseColumns('users', $columns, 'id');

        self::assertSame('Users', $entity->className);
        self::assertSame('App\\Entity', $entity->namespace);
        self::assertSame('users', $entity->tableName);
        self::assertCount(5, $entity->properties);
        self::assertSame([], $entity->relationships);
        self::assertSame('id', $entity->primaryKey);
        self::assertTrue($entity->hasTimestamps);
        self::assertFalse($entity->hasSoftDeletes);
        self::assertFalse($entity->isAuditAware);
    }

    #[Test]
    public function fromDatabaseColumnsDetectsTimestamps(): void
    {
        $columns = [
            new ColumnInfo('id', 'int', false, true, null),
            new ColumnInfo('created_at', 'timestamp', true, false, null),
            new ColumnInfo('updated_at', 'timestamp', true, false, null),
        ];

        $entity = EntityDefinition::fromDatabaseColumns('items', $columns, 'id');

        self::assertTrue($entity->hasTimestamps);
    }

    #[Test]
    public function fromDatabaseColumnsDetectsSoftDeletes(): void
    {
        $columns = [
            new ColumnInfo('id', 'int', false, true, null),
            new ColumnInfo('deleted_at', 'timestamp', true, false, null),
        ];

        $entity = EntityDefinition::fromDatabaseColumns('items', $columns, 'id');

        self::assertTrue($entity->hasSoftDeletes);
    }

    #[Test]
    public function fromDatabaseColumnsDetectsAuditColumns(): void
    {
        $columns = [
            new ColumnInfo('id', 'int', false, true, null),
            new ColumnInfo('created_by', 'int', true, false, null),
            new ColumnInfo('updated_by', 'int', true, false, null),
        ];

        $entity = EntityDefinition::fromDatabaseColumns('items', $columns, 'id');

        self::assertTrue($entity->isAuditAware);
    }

    #[Test]
    public function fromDatabaseColumnsNoTimestampsIfPartial(): void
    {
        $columns = [
            new ColumnInfo('id', 'int', false, true, null),
            new ColumnInfo('created_at', 'timestamp', true, false, null),
            // Missing updated_at
        ];

        $entity = EntityDefinition::fromDatabaseColumns('items', $columns, 'id');

        self::assertFalse($entity->hasTimestamps);
    }

    #[Test]
    public function fromDatabaseColumnsUsesCustomNamespace(): void
    {
        $columns = [
            new ColumnInfo('id', 'int', false, true, null),
        ];

        $entity = EntityDefinition::fromDatabaseColumns(
            'items',
            $columns,
            'id',
            'Domain\\Model',
        );

        self::assertSame('Domain\\Model', $entity->namespace);
    }

    #[Test]
    public function fromDatabaseColumnsMapsPropertyTypes(): void
    {
        $columns = [
            new ColumnInfo('id', 'bigint', false, true, null),
            new ColumnInfo('name', 'varchar(100)', false, false, null),
            new ColumnInfo('price', 'decimal', false, false, null),
            new ColumnInfo('active', 'boolean', false, false, null),
            new ColumnInfo('metadata', 'json', true, false, null),
        ];

        $entity = EntityDefinition::fromDatabaseColumns('products', $columns, 'id');
        $props = $entity->properties;

        self::assertSame('int', $props[0]->phpType);
        self::assertSame('string', $props[1]->phpType);
        self::assertSame('float', $props[2]->phpType);
        self::assertSame('bool', $props[3]->phpType);
        self::assertSame('array', $props[4]->phpType);
    }

    #[Test]
    public function fromDatabaseColumnsPreservesColumnMapping(): void
    {
        $columns = [
            new ColumnInfo('user_name', 'varchar(50)', false, false, null),
        ];

        $entity = EntityDefinition::fromDatabaseColumns('users', $columns, 'id');

        self::assertSame('userName', $entity->properties[0]->name);
        self::assertSame('user_name', $entity->properties[0]->columnName);
    }

    #[Test]
    public function fromDatabaseColumnsDetectsColumnWithDefault(): void
    {
        $columns = [
            new ColumnInfo('status', 'varchar(20)', false, false, 'active'),
        ];

        $entity = EntityDefinition::fromDatabaseColumns('orders', $columns, 'id');

        self::assertTrue($entity->properties[0]->hasDefault);
        self::assertSame('active', $entity->properties[0]->defaultValue);
    }

    #[Test]
    public function toArrayProducesSortedKeys(): void
    {
        $entity = new EntityDefinition(
            className: 'Post',
            namespace: 'App\\Entity',
            tableName: 'posts',
            properties: [],
            relationships: [],
            primaryKey: 'id',
            hasTimestamps: false,
            hasSoftDeletes: false,
            isAuditAware: false,
        );

        $array = $entity->toArray();
        $keys = array_keys($array);

        $sorted = $keys;
        sort($sorted);

        self::assertSame($sorted, $keys);
    }

    #[Test]
    public function fromArrayRoundTrips(): void
    {
        $entity = new EntityDefinition(
            className: 'User',
            namespace: 'App\\Entity',
            tableName: 'users',
            properties: [
                new PropertyDefinition(
                    name: 'id',
                    phpType: 'int',
                    columnName: 'id',
                    columnType: 'bigint',
                    nullable: false,
                    hasDefault: true,
                    defaultValue: null,
                    validationRules: [],
                    isFilterable: false,
                    isSortable: true,
                    length: null,
                    isPrimaryKey: true,
                ),
            ],
            relationships: [
                new RelationshipDefinition(
                    type: RelationType::HasMany,
                    relatedEntity: 'Post',
                    foreignKey: 'user_id',
                    localKey: 'id',
                ),
            ],
            primaryKey: 'id',
            hasTimestamps: true,
            hasSoftDeletes: false,
            isAuditAware: false,
        );

        $restored = EntityDefinition::fromArray($entity->toArray());

        self::assertSame($entity->className, $restored->className);
        self::assertSame($entity->namespace, $restored->namespace);
        self::assertSame($entity->tableName, $restored->tableName);
        self::assertCount(1, $restored->properties);
        self::assertCount(1, $restored->relationships);
        self::assertSame($entity->primaryKey, $restored->primaryKey);
        self::assertSame($entity->hasTimestamps, $restored->hasTimestamps);
        self::assertSame($entity->hasSoftDeletes, $restored->hasSoftDeletes);
        self::assertSame($entity->isAuditAware, $restored->isAuditAware);
    }

    #[Test]
    public function fromArrayWithMissingKeysUsesDefaults(): void
    {
        $entity = EntityDefinition::fromArray([]);

        self::assertSame('', $entity->className);
        self::assertSame('', $entity->namespace);
        self::assertSame('', $entity->tableName);
        self::assertSame([], $entity->properties);
        self::assertSame([], $entity->relationships);
        self::assertSame('id', $entity->primaryKey);
        self::assertFalse($entity->hasTimestamps);
        self::assertFalse($entity->hasSoftDeletes);
        self::assertFalse($entity->isAuditAware);
    }
}
