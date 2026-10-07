<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Schema;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
use Pulsar\Database\Schema\SchemaException;

final class SchemaExceptionTest extends TestCase
{
    #[Test]
    public function invalid_identifier_includes_type_and_name(): void
    {
        $ex = SchemaException::invalidIdentifier('column', 'bad col', 'contains space');

        self::assertStringContainsString('column', $ex->getMessage());
        self::assertStringContainsString('bad col', $ex->getMessage());
        self::assertStringContainsString('contains space', $ex->getMessage());
    }

    #[Test]
    public function operation_not_supported_includes_driver_and_operation(): void
    {
        $ex = SchemaException::operationNotSupported(Driver::SQLite, 'DROP COLUMN');

        self::assertStringContainsString('DROP COLUMN', $ex->getMessage());
        self::assertStringContainsString('sqlite', $ex->getMessage());
    }

    #[Test]
    public function table_already_exists_includes_table_name(): void
    {
        $ex = SchemaException::tableAlreadyExists('users');

        self::assertStringContainsString('users', $ex->getMessage());
        self::assertStringContainsString('already exists', $ex->getMessage());
    }

    #[Test]
    public function table_not_found_includes_table_name(): void
    {
        $ex = SchemaException::tableNotFound('orders');

        self::assertStringContainsString('orders', $ex->getMessage());
        self::assertStringContainsString('does not exist', $ex->getMessage());
    }

    #[Test]
    public function column_not_found_includes_table_and_column(): void
    {
        $ex = SchemaException::columnNotFound('users', 'deleted_at');

        self::assertStringContainsString('deleted_at', $ex->getMessage());
        self::assertStringContainsString('users', $ex->getMessage());
    }

    #[Test]
    public function column_already_exists_includes_table_and_column(): void
    {
        $ex = SchemaException::columnAlreadyExists('users', 'email');

        self::assertStringContainsString('email', $ex->getMessage());
        self::assertStringContainsString('users', $ex->getMessage());
        self::assertStringContainsString('already exists', $ex->getMessage());
    }
}
