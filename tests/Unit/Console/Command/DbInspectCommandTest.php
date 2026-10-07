<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\DbInspectCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Database\Introspection\ColumnInfo;
use Pulsar\Database\Introspection\DatabaseIntrospectorInterface;
use Pulsar\Database\Introspection\TableInfo;

#[CoversClass(DbInspectCommand::class)]
final class DbInspectCommandTest extends TestCase
{
    #[Test]
    public function it_has_correct_name_and_description(): void
    {
        $introspector = $this->createStub(DatabaseIntrospectorInterface::class);
        $command = new DbInspectCommand($introspector);

        self::assertSame('db:inspect', $command->name);
        self::assertNotEmpty($command->description);
    }

    #[Test]
    public function it_lists_tables_when_no_argument_given(): void
    {
        $introspector = $this->createStub(DatabaseIntrospectorInterface::class);
        $introspector->method('tables')->willReturn([
            new TableInfo('users'),
            new TableInfo('posts'),
        ]);
        $introspector->method('columns')->willReturn([
            new ColumnInfo('id', 'integer', false, true, null),
        ]);
        $introspector->method('primaryKey')->willReturn('id');

        $command = new DbInspectCommand($introspector);
        $input = new ArrayInput(arguments: []);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
        self::assertStringContainsString('users', $output->buffer);
        self::assertStringContainsString('posts', $output->buffer);
        self::assertStringContainsString('2', $output->buffer); // table count
    }

    #[Test]
    public function it_shows_no_tables_message_when_database_is_empty(): void
    {
        $introspector = $this->createStub(DatabaseIntrospectorInterface::class);
        $introspector->method('tables')->willReturn([]);

        $command = new DbInspectCommand($introspector);
        $input = new ArrayInput(arguments: []);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
        self::assertStringContainsString('No tables found', $output->buffer);
    }

    #[Test]
    public function it_inspects_specific_table(): void
    {
        $introspector = $this->createStub(DatabaseIntrospectorInterface::class);
        $introspector->method('columns')->willReturn([
            new ColumnInfo('id', 'integer', false, true, null),
            new ColumnInfo('name', 'varchar(255)', false, false, null),
            new ColumnInfo('email', 'varchar(255)', true, false, null),
        ]);
        $introspector->method('primaryKey')->willReturn('id');

        $command = new DbInspectCommand($introspector);
        $input = new ArrayInput(arguments: ['users']);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
        self::assertStringContainsString('users', $output->buffer);
        self::assertStringContainsString('id', $output->buffer);
        self::assertStringContainsString('name', $output->buffer);
        self::assertStringContainsString('email', $output->buffer);
        self::assertStringContainsString('integer', $output->buffer);
    }

    #[Test]
    public function it_returns_error_for_nonexistent_table(): void
    {
        $introspector = $this->createStub(DatabaseIntrospectorInterface::class);
        $introspector->method('columns')->willReturn([]);

        $command = new DbInspectCommand($introspector);
        $input = new ArrayInput(arguments: ['nonexistent']);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Error->value, $exitCode);
        self::assertStringContainsString('nonexistent', $output->errorBuffer);
    }

    #[Test]
    public function it_shows_columns_for_all_tables_with_columns_option(): void
    {
        $introspector = $this->createStub(DatabaseIntrospectorInterface::class);
        $introspector->method('tables')->willReturn([
            new TableInfo('users'),
        ]);
        $introspector->method('columns')->willReturn([
            new ColumnInfo('id', 'integer', false, true, null),
        ]);
        $introspector->method('primaryKey')->willReturn('id');

        $command = new DbInspectCommand($introspector);
        $input = new ArrayInput(arguments: [], options: ['columns' => true]);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
        self::assertStringContainsString('Columns for', $output->buffer);
    }
}
