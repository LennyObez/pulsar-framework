<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Codegen\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Codegen\Schema\SchemaOperation;
use Pulsar\Codegen\Schema\SchemaOperationType;
use RuntimeException;

#[CoversClass(SchemaOperation::class)]
#[CoversClass(SchemaOperationType::class)]
final class SchemaOperationTest extends TestCase
{
    #[Test]
    public function constructWithMinimalProperties(): void
    {
        $op = new SchemaOperation(
            type: SchemaOperationType::CreateTable,
            table: 'users',
        );

        self::assertSame(SchemaOperationType::CreateTable, $op->type);
        self::assertSame('users', $op->table);
        self::assertNull($op->column);
        self::assertSame([], $op->metadata);
    }

    #[Test]
    public function constructWithAllProperties(): void
    {
        $op = new SchemaOperation(
            type: SchemaOperationType::AddColumn,
            table: 'users',
            column: 'email',
            metadata: ['phpType' => 'string', 'columnType' => 'varchar(255)'],
        );

        self::assertSame(SchemaOperationType::AddColumn, $op->type);
        self::assertSame('users', $op->table);
        self::assertSame('email', $op->column);
        self::assertSame('string', $op->metadata['phpType']);
    }

    #[Test]
    public function toArraySortsMetadataKeys(): void
    {
        $op = new SchemaOperation(
            type: SchemaOperationType::ModifyColumn,
            table: 'posts',
            column: 'title',
            metadata: ['newType' => 'text', 'oldType' => 'varchar(255)'],
        );

        $array = $op->toArray();
        $metaKeys = array_keys($array['metadata']);

        self::assertSame(['newType', 'oldType'], $metaKeys);
    }

    #[Test]
    public function fromArrayRoundTrips(): void
    {
        $original = new SchemaOperation(
            type: SchemaOperationType::DropColumn,
            table: 'users',
            column: 'legacy_field',
            metadata: ['reason' => 'deprecated'],
        );

        $restored = SchemaOperation::fromArray($original->toArray());

        self::assertSame($original->type, $restored->type);
        self::assertSame($original->table, $restored->table);
        self::assertSame($original->column, $restored->column);
        self::assertSame($original->metadata, $restored->metadata);
    }

    /**
     * A corrupted or future-version persisted diff may carry an unknown
     * operation `type`. Deserialization must surface a catchable
     * RuntimeException rather than an uncatchable \ValueError, so the
     * CLI can report a useful error instead of crashing.
     */
    #[Test]
    public function fromArrayThrowsCatchableExceptionForUnknownType(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Invalid schema operation type "not_a_real_op"');

        (void) SchemaOperation::fromArray([
            'type' => 'not_a_real_op',
            'table' => 'users',
        ]);
    }

    #[Test]
    public function allOperationTypesHaveValues(): void
    {
        $cases = SchemaOperationType::cases();

        self::assertCount(9, $cases);
        self::assertSame('create_table', SchemaOperationType::CreateTable->value);
        self::assertSame('drop_table', SchemaOperationType::DropTable->value);
        self::assertSame('add_column', SchemaOperationType::AddColumn->value);
        self::assertSame('drop_column', SchemaOperationType::DropColumn->value);
        self::assertSame('modify_column', SchemaOperationType::ModifyColumn->value);
        self::assertSame('add_index', SchemaOperationType::AddIndex->value);
        self::assertSame('drop_index', SchemaOperationType::DropIndex->value);
        self::assertSame('add_foreign_key', SchemaOperationType::AddForeignKey->value);
        self::assertSame('drop_foreign_key', SchemaOperationType::DropForeignKey->value);
    }
}
