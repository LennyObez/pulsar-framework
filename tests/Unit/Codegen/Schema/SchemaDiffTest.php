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
use Pulsar\Codegen\Schema\SchemaDiff;
use Pulsar\Codegen\Schema\SchemaOperationType;
use Pulsar\Codegen\Schema\SchemaSnapshot;

#[CoversClass(SchemaDiff::class)]
final class SchemaDiffTest extends TestCase
{
    private SchemaDiff $differ;

    protected function setUp(): void
    {
        $this->differ = new SchemaDiff();
    }

    #[Test]
    public function identicalSnapshotsProduceNoDiff(): void
    {
        $entity = $this->makeEntity('users', 'User', [
            $this->makeProperty('id', 'int', 'id', 'bigint', isPrimaryKey: true),
            $this->makeProperty('name', 'string', 'name', 'varchar(255)'),
        ]);

        $old = new SchemaSnapshot(['users' => $entity], '1');
        $new = new SchemaSnapshot(['users' => $entity], '1');

        $result = $this->differ->diff($old, $new);

        self::assertFalse($result->hasChanges());
    }

    #[Test]
    public function detectsAddedTable(): void
    {
        $old = new SchemaSnapshot([], '1');
        $new = new SchemaSnapshot([
            'users' => $this->makeEntity('users', 'User', [
                $this->makeProperty('id', 'int', 'id', 'bigint', isPrimaryKey: true),
                $this->makeProperty('name', 'string', 'name', 'varchar(255)'),
            ]),
        ], '1');

        $result = $this->differ->diff($old, $new);

        self::assertTrue($result->hasChanges());

        $creates = $result->ofType(SchemaOperationType::CreateTable);
        self::assertCount(1, $creates);
        self::assertSame('users', $creates[0]->table);

        // Should also have AddColumn operations for each property
        $adds = $result->ofType(SchemaOperationType::AddColumn);
        self::assertCount(2, $adds);
    }

    #[Test]
    public function detectsRemovedTable(): void
    {
        $old = new SchemaSnapshot([
            'users' => $this->makeEntity('users', 'User', [
                $this->makeProperty('id', 'int', 'id', 'bigint', isPrimaryKey: true),
            ]),
        ], '1');
        $new = new SchemaSnapshot([], '1');

        $result = $this->differ->diff($old, $new);

        self::assertTrue($result->hasChanges());

        $drops = $result->ofType(SchemaOperationType::DropTable);
        self::assertCount(1, $drops);
        self::assertSame('users', $drops[0]->table);
    }

    #[Test]
    public function detectsAddedColumn(): void
    {
        $oldEntity = $this->makeEntity('users', 'User', [
            $this->makeProperty('id', 'int', 'id', 'bigint', isPrimaryKey: true),
        ]);
        $newEntity = $this->makeEntity('users', 'User', [
            $this->makeProperty('id', 'int', 'id', 'bigint', isPrimaryKey: true),
            $this->makeProperty('email', 'string', 'email', 'varchar(200)'),
        ]);

        $old = new SchemaSnapshot(['users' => $oldEntity], '1');
        $new = new SchemaSnapshot(['users' => $newEntity], '1');

        $result = $this->differ->diff($old, $new);

        $adds = $result->ofType(SchemaOperationType::AddColumn);
        self::assertCount(1, $adds);
        self::assertSame('users', $adds[0]->table);
        self::assertSame('email', $adds[0]->column);
    }

    #[Test]
    public function detectsRemovedColumn(): void
    {
        $oldEntity = $this->makeEntity('users', 'User', [
            $this->makeProperty('id', 'int', 'id', 'bigint', isPrimaryKey: true),
            $this->makeProperty('legacy', 'string', 'legacy', 'varchar(100)'),
        ]);
        $newEntity = $this->makeEntity('users', 'User', [
            $this->makeProperty('id', 'int', 'id', 'bigint', isPrimaryKey: true),
        ]);

        $old = new SchemaSnapshot(['users' => $oldEntity], '1');
        $new = new SchemaSnapshot(['users' => $newEntity], '1');

        $result = $this->differ->diff($old, $new);

        $drops = $result->ofType(SchemaOperationType::DropColumn);
        self::assertCount(1, $drops);
        self::assertSame('legacy', $drops[0]->column);
    }

    #[Test]
    public function detectsModifiedColumn(): void
    {
        $oldEntity = $this->makeEntity('users', 'User', [
            $this->makeProperty('id', 'int', 'id', 'bigint', isPrimaryKey: true),
            $this->makeProperty('name', 'string', 'name', 'varchar(100)'),
        ]);
        $newEntity = $this->makeEntity('users', 'User', [
            $this->makeProperty('id', 'int', 'id', 'bigint', isPrimaryKey: true),
            $this->makeProperty('name', 'string', 'name', 'varchar(255)'),
        ]);

        $old = new SchemaSnapshot(['users' => $oldEntity], '1');
        $new = new SchemaSnapshot(['users' => $newEntity], '1');

        $result = $this->differ->diff($old, $new);

        $modifies = $result->ofType(SchemaOperationType::ModifyColumn);
        self::assertCount(1, $modifies);
        self::assertSame('name', $modifies[0]->column);
        self::assertSame('varchar(100)', $modifies[0]->metadata['oldType']);
        self::assertSame('varchar(255)', $modifies[0]->metadata['newType']);
    }

    #[Test]
    public function detectsNullabilityChange(): void
    {
        $oldEntity = $this->makeEntity('users', 'User', [
            $this->makeProperty('id', 'int', 'id', 'bigint', isPrimaryKey: true),
            $this->makeProperty('email', 'string', 'email', 'varchar(200)', nullable: false),
        ]);
        $newEntity = $this->makeEntity('users', 'User', [
            $this->makeProperty('id', 'int', 'id', 'bigint', isPrimaryKey: true),
            $this->makeProperty('email', 'string', 'email', 'varchar(200)', nullable: true),
        ]);

        $old = new SchemaSnapshot(['users' => $oldEntity], '1');
        $new = new SchemaSnapshot(['users' => $newEntity], '1');

        $result = $this->differ->diff($old, $new);

        $modifies = $result->ofType(SchemaOperationType::ModifyColumn);
        self::assertCount(1, $modifies);
        self::assertFalse($modifies[0]->metadata['oldNullable']);
        self::assertTrue($modifies[0]->metadata['newNullable']);
    }

    #[Test]
    public function detectsAddedForeignKey(): void
    {
        $oldEntity = $this->makeEntity('posts', 'Post', [
            $this->makeProperty('id', 'int', 'id', 'bigint', isPrimaryKey: true),
        ]);
        $newEntity = new EntityDefinition(
            className: 'Post',
            namespace: 'App\\Entity',
            tableName: 'posts',
            properties: [
                $this->makeProperty('id', 'int', 'id', 'bigint', isPrimaryKey: true),
            ],
            relationships: [
                new RelationshipDefinition(
                    type: RelationType::BelongsTo,
                    relatedEntity: 'User',
                    foreignKey: 'author_id',
                    localKey: 'id',
                ),
            ],
            primaryKey: 'id',
            hasTimestamps: false,
            hasSoftDeletes: false,
            isAuditAware: false,
        );

        $old = new SchemaSnapshot(['posts' => $oldEntity], '1');
        $new = new SchemaSnapshot(['posts' => $newEntity], '1');

        $result = $this->differ->diff($old, $new);

        $addFks = $result->ofType(SchemaOperationType::AddForeignKey);
        self::assertCount(1, $addFks);
        self::assertSame('author_id', $addFks[0]->column);
        self::assertSame('User', $addFks[0]->metadata['relatedEntity']);
    }

    #[Test]
    public function detectsRemovedForeignKey(): void
    {
        $oldEntity = new EntityDefinition(
            className: 'Post',
            namespace: 'App\\Entity',
            tableName: 'posts',
            properties: [
                $this->makeProperty('id', 'int', 'id', 'bigint', isPrimaryKey: true),
            ],
            relationships: [
                new RelationshipDefinition(
                    type: RelationType::BelongsTo,
                    relatedEntity: 'User',
                    foreignKey: 'author_id',
                    localKey: 'id',
                ),
            ],
            primaryKey: 'id',
            hasTimestamps: false,
            hasSoftDeletes: false,
            isAuditAware: false,
        );
        $newEntity = $this->makeEntity('posts', 'Post', [
            $this->makeProperty('id', 'int', 'id', 'bigint', isPrimaryKey: true),
        ]);

        $old = new SchemaSnapshot(['posts' => $oldEntity], '1');
        $new = new SchemaSnapshot(['posts' => $newEntity], '1');

        $result = $this->differ->diff($old, $new);

        $dropFks = $result->ofType(SchemaOperationType::DropForeignKey);
        self::assertCount(1, $dropFks);
        self::assertSame('author_id', $dropFks[0]->column);
    }

    #[Test]
    public function droppedTableDropsForeignKeysFirst(): void
    {
        $entity = new EntityDefinition(
            className: 'Post',
            namespace: 'App\\Entity',
            tableName: 'posts',
            properties: [
                $this->makeProperty('id', 'int', 'id', 'bigint', isPrimaryKey: true),
            ],
            relationships: [
                new RelationshipDefinition(
                    type: RelationType::BelongsTo,
                    relatedEntity: 'User',
                    foreignKey: 'author_id',
                    localKey: 'id',
                ),
            ],
            primaryKey: 'id',
            hasTimestamps: false,
            hasSoftDeletes: false,
            isAuditAware: false,
        );

        $old = new SchemaSnapshot(['posts' => $entity], '1');
        $new = new SchemaSnapshot([], '1');

        $result = $this->differ->diff($old, $new);

        // DropForeignKey should come before DropTable
        $types = array_map(
            static fn($op) => $op->type,
            $result->operations,
        );

        $fkIndex = array_search(SchemaOperationType::DropForeignKey, $types, true);
        $tableIndex = array_search(SchemaOperationType::DropTable, $types, true);

        self::assertNotFalse($fkIndex);
        self::assertNotFalse($tableIndex);
        self::assertLessThan($tableIndex, $fkIndex);
    }

    #[Test]
    public function complexDiffWithMultipleChanges(): void
    {
        $old = new SchemaSnapshot([
            'users' => $this->makeEntity('users', 'User', [
                $this->makeProperty('id', 'int', 'id', 'bigint', isPrimaryKey: true),
                $this->makeProperty('name', 'string', 'name', 'varchar(100)'),
                $this->makeProperty('legacy', 'string', 'legacy', 'text'),
            ]),
            'old_table' => $this->makeEntity('old_table', 'OldTable', [
                $this->makeProperty('id', 'int', 'id', 'int', isPrimaryKey: true),
            ]),
        ], '1');

        $new = new SchemaSnapshot([
            'users' => $this->makeEntity('users', 'User', [
                $this->makeProperty('id', 'int', 'id', 'bigint', isPrimaryKey: true),
                $this->makeProperty('name', 'string', 'name', 'varchar(255)'),
                $this->makeProperty('email', 'string', 'email', 'varchar(200)'),
            ]),
            'posts' => $this->makeEntity('posts', 'Post', [
                $this->makeProperty('id', 'int', 'id', 'bigint', isPrimaryKey: true),
                $this->makeProperty('title', 'string', 'title', 'varchar(300)'),
            ]),
        ], '2');

        $result = $this->differ->diff($old, $new);

        self::assertTrue($result->hasChanges());

        // New table: posts
        self::assertCount(1, $result->ofType(SchemaOperationType::CreateTable));

        // Removed table: old_table
        self::assertCount(1, $result->ofType(SchemaOperationType::DropTable));

        // Modified column: name varchar(100) -> varchar(255)
        self::assertCount(1, $result->ofType(SchemaOperationType::ModifyColumn));

        // Added column on users: email, plus columns on posts: id, title
        $addColumns = $result->ofType(SchemaOperationType::AddColumn);
        self::assertCount(3, $addColumns);

        // Dropped column on users: legacy
        self::assertCount(1, $result->ofType(SchemaOperationType::DropColumn));
    }

    #[Test]
    public function emptyToEmptyProducesNoDiff(): void
    {
        $old = new SchemaSnapshot([], '1');
        $new = new SchemaSnapshot([], '1');

        $result = $this->differ->diff($old, $new);

        self::assertFalse($result->hasChanges());
    }

    /**
     * @param list<PropertyDefinition> $properties
     */
    private function makeEntity(string $tableName, string $className, array $properties): EntityDefinition
    {
        return new EntityDefinition(
            className: $className,
            namespace: 'App\\Entity',
            tableName: $tableName,
            properties: $properties,
            relationships: [],
            primaryKey: 'id',
            hasTimestamps: false,
            hasSoftDeletes: false,
            isAuditAware: false,
        );
    }

    private function makeProperty(
        string $name,
        string $phpType,
        string $columnName,
        string $columnType,
        bool $nullable = false,
        bool $isPrimaryKey = false,
    ): PropertyDefinition {
        return new PropertyDefinition(
            name: $name,
            phpType: $phpType,
            columnName: $columnName,
            columnType: $columnType,
            nullable: $nullable,
            hasDefault: false,
            defaultValue: null,
            validationRules: [],
            isFilterable: true,
            isSortable: true,
            length: null,
            isPrimaryKey: $isPrimaryKey,
        );
    }
}
