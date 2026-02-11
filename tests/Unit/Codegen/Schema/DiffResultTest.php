<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Codegen\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Codegen\Schema\DiffResult;
use Pulsar\Codegen\Schema\SchemaOperation;
use Pulsar\Codegen\Schema\SchemaOperationType;

#[CoversClass(DiffResult::class)]
final class DiffResultTest extends TestCase
{
    #[Test]
    public function emptyResultHasNoChanges(): void
    {
        $result = new DiffResult([]);

        self::assertFalse($result->hasChanges());
        self::assertSame([], $result->operations);
    }

    #[Test]
    public function nonEmptyResultHasChanges(): void
    {
        $result = new DiffResult([
            new SchemaOperation(SchemaOperationType::CreateTable, 'users'),
        ]);

        self::assertTrue($result->hasChanges());
    }

    #[Test]
    public function ofTypeFiltersCorrectly(): void
    {
        $result = new DiffResult([
            new SchemaOperation(SchemaOperationType::CreateTable, 'users'),
            new SchemaOperation(SchemaOperationType::AddColumn, 'users', 'email'),
            new SchemaOperation(SchemaOperationType::AddColumn, 'users', 'name'),
            new SchemaOperation(SchemaOperationType::CreateTable, 'posts'),
        ]);

        $creates = $result->ofType(SchemaOperationType::CreateTable);
        $adds = $result->ofType(SchemaOperationType::AddColumn);
        $drops = $result->ofType(SchemaOperationType::DropTable);

        self::assertCount(2, $creates);
        self::assertCount(2, $adds);
        self::assertCount(0, $drops);
    }

    #[Test]
    public function toArraySerializesOperations(): void
    {
        $result = new DiffResult([
            new SchemaOperation(SchemaOperationType::CreateTable, 'users'),
            new SchemaOperation(SchemaOperationType::AddColumn, 'users', 'name'),
        ]);

        $array = $result->toArray();

        self::assertCount(2, $array);
        self::assertSame('create_table', $array[0]['type']);
        self::assertSame('add_column', $array[1]['type']);
    }

    #[Test]
    public function fromArrayRoundTrips(): void
    {
        $original = new DiffResult([
            new SchemaOperation(
                SchemaOperationType::ModifyColumn,
                'users',
                'email',
                ['oldType' => 'varchar(100)', 'newType' => 'varchar(255)'],
            ),
        ]);

        $restored = DiffResult::fromArray($original->toArray());

        self::assertCount(1, $restored->operations);
        self::assertSame(SchemaOperationType::ModifyColumn, $restored->operations[0]->type);
        self::assertSame('users', $restored->operations[0]->table);
        self::assertSame('email', $restored->operations[0]->column);
    }
}
