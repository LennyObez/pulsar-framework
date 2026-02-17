<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\DbFreshCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Introspection\DatabaseIntrospectorInterface;
use Pulsar\Database\Introspection\TableInfo;
use Pulsar\Database\Migration\MigrationRunnerInterface;
use Pulsar\Database\Seeder\SeederRunnerInterface;

use function assert;
use function is_resource;

#[CoversClass(DbFreshCommand::class)]
final class DbFreshCommandTest extends TestCase
{
    #[Test]
    public function it_has_correct_name(): void
    {
        $command = $this->createCommand();

        self::assertSame('db:fresh', $command->name);
    }

    #[Test]
    public function it_cancels_without_force_when_user_declines(): void
    {
        $stdin = $this->createStream("n\n");
        $command = $this->createCommand(stdin: $stdin);

        $input = new ArrayInput(arguments: []);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
        self::assertStringContainsString('cancelled', $output->buffer);
    }

    #[Test]
    public function it_proceeds_with_force_option(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);
        $connection->method('execute')->willReturn(0);

        $introspector = $this->createStub(DatabaseIntrospectorInterface::class);
        $introspector->method('tables')->willReturn([]);

        $migrationRunner = $this->createStub(MigrationRunnerInterface::class);
        $migrationRunner->method('runPending')->willReturn([]);

        $command = new DbFreshCommand($connection, $introspector, $migrationRunner);

        $input = new ArrayInput(arguments: [], options: ['force' => true]);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
        self::assertStringContainsString('refreshed successfully', $output->buffer);
    }

    #[Test]
    public function it_drops_tables_and_runs_migrations(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);
        $connection->method('execute')->willReturn(0);

        $introspector = $this->createStub(DatabaseIntrospectorInterface::class);
        $introspector->method('tables')->willReturn([
            new TableInfo('users'),
            new TableInfo('posts'),
        ]);

        $migrationRunner = $this->createStub(MigrationRunnerInterface::class);
        $migrationRunner->method('runPending')->willReturn([
            '001_create_users',
            '002_create_posts',
        ]);

        $command = new DbFreshCommand($connection, $introspector, $migrationRunner);

        $input = new ArrayInput(arguments: [], options: ['force' => true]);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
        self::assertStringContainsString('Dropped 2 table(s)', $output->buffer);
        self::assertStringContainsString('001_create_users', $output->buffer);
        self::assertStringContainsString('002_create_posts', $output->buffer);
    }

    #[Test]
    public function it_runs_seeders_when_seed_option_present(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);
        $connection->method('execute')->willReturn(0);

        $introspector = $this->createStub(DatabaseIntrospectorInterface::class);
        $introspector->method('tables')->willReturn([]);

        $migrationRunner = $this->createStub(MigrationRunnerInterface::class);
        $migrationRunner->method('runPending')->willReturn([]);

        $seederRunner = $this->createStub(SeederRunnerInterface::class);
        $seederRunner->method('runAll')->willReturn(['UserSeeder', 'PostSeeder']);

        $command = new DbFreshCommand($connection, $introspector, $migrationRunner, $seederRunner);

        $input = new ArrayInput(arguments: [], options: ['force' => true, 'seed' => true]);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
        self::assertStringContainsString('UserSeeder', $output->buffer);
        self::assertStringContainsString('PostSeeder', $output->buffer);
    }

    #[Test]
    public function it_proceeds_when_user_confirms_without_force(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);
        $connection->method('execute')->willReturn(0);

        $introspector = $this->createStub(DatabaseIntrospectorInterface::class);
        $introspector->method('tables')->willReturn([]);

        $migrationRunner = $this->createStub(MigrationRunnerInterface::class);
        $migrationRunner->method('runPending')->willReturn([]);

        $stdin = $this->createStream("y\n");
        $command = new DbFreshCommand($connection, $introspector, $migrationRunner, stdin: $stdin);

        $input = new ArrayInput(arguments: []);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
        self::assertStringContainsString('refreshed successfully', $output->buffer);
    }

    #[Test]
    public function it_shows_warning_about_data_loss(): void
    {
        $stdin = $this->createStream("n\n");
        $command = $this->createCommand(stdin: $stdin);

        $input = new ArrayInput(arguments: []);
        $output = new BufferedOutput();

        $command->execute($input, $output);

        self::assertStringContainsString('WARNING', $output->buffer);
        self::assertStringContainsString('irreversible', $output->buffer);
    }

    /**
     * @param resource|null $stdin
     */
    private function createCommand(mixed $stdin = null): DbFreshCommand
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);

        $introspector = $this->createStub(DatabaseIntrospectorInterface::class);
        $introspector->method('tables')->willReturn([]);

        $migrationRunner = $this->createStub(MigrationRunnerInterface::class);
        $migrationRunner->method('runPending')->willReturn([]);

        assert(is_resource($stdin) || $stdin === null);

        return new DbFreshCommand($connection, $introspector, $migrationRunner, stdin: $stdin);
    }

    /**
     * @return resource
     */
    private function createStream(string $content): mixed
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    }
}
