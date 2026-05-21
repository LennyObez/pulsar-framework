<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Introspection\DatabaseIntrospectorInterface;
use Pulsar\Database\Migration\MigrationRunnerInterface;
use Pulsar\Database\Seeder\SeederRunnerInterface;
use Throwable;

use function count;
use function fgets;
use function sprintf;
use function strtolower;
use function trim;

/**
 * Drop all tables, re-run migrations, and optionally seed.
 *
 * This is a destructive operation that requires confirmation unless --force is passed.
 *
 * Usage: db:fresh [--force] [--seed]
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal]
final class DbFreshCommand extends Command
{
    /**
     * @param resource|null $stdin Readable stream for confirmation input (null uses STDIN)
     */
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly DatabaseIntrospectorInterface $introspector,
        private readonly MigrationRunnerInterface $migrationRunner,
        private readonly ?SeederRunnerInterface $seederRunner = null,
        private mixed $stdin = null,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'db:fresh';
        $this->description = 'Drop all tables, re-run migrations, and optionally seed';
        $this->addOption('force', 'Skip confirmation prompt', 'f');
        $this->addOption('seed', 'Run seeders after migrations', 's');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $force = $input->hasOption('force');
        $seed = $input->hasOption('seed');

        if (!$force) {
            $output->warning('WARNING: This will drop ALL tables and data in the database.');
            $output->writeln('This action is irreversible. All data will be permanently lost.');
            $output->newLine();
            $output->write('Are you sure you want to continue? [y/N] ');

            $stream = $this->stdin ?? STDIN;
            $answer = fgets($stream);

            if ($answer === false || strtolower(trim($answer)) !== 'y') {
                $output->writeln('Operation cancelled.');
                return ExitCode::Success->value;
            }

            $output->newLine();
        }

        // Step 1: Drop all tables
        $output->writeln('Dropping all tables...');

        try {
            $tables = $this->introspector->tables();
            $droppedCount = $this->dropAllTables($tables);
            $output->writeln(sprintf('  Dropped %d table(s).', $droppedCount));
        } catch (Throwable $e) {
            $output->errorln(sprintf('Failed to drop tables: %s', $e->getMessage()));
            return ExitCode::Error->value;
        }

        // Step 2: Re-run migrations
        $output->newLine();
        $output->writeln('Running migrations...');

        try {
            $this->migrationRunner->ensureMigrationTable();
            $ran = $this->migrationRunner->runPending();

            if ($ran === []) {
                $output->writeln('  No migrations to run.');
            } else {
                foreach ($ran as $migration) {
                    $output->writeln(sprintf('  Migrated: %s', $migration));
                }
                $output->writeln(sprintf('  Ran %d migration(s).', count($ran)));
            }
        } catch (Throwable $e) {
            $output->errorln(sprintf('Migration failed: %s', $e->getMessage()));
            return ExitCode::Error->value;
        }

        // Step 3: Optionally seed
        if ($seed && $this->seederRunner !== null) {
            $output->newLine();
            $output->writeln('Running seeders...');

            try {
                $executed = $this->seederRunner->runAll();

                if ($executed === []) {
                    $output->writeln('  No seeders found.');
                } else {
                    foreach ($executed as $name) {
                        $output->writeln(sprintf('  Seeded: %s', $name));
                    }
                    $output->writeln(sprintf('  Ran %d seeder(s).', count($executed)));
                }
            } catch (Throwable $e) {
                $output->errorln(sprintf('Seeding failed: %s', $e->getMessage()));
                return ExitCode::Error->value;
            }
        }

        $output->newLine();
        $output->success('Database refreshed successfully.');

        return ExitCode::Success->value;
    }

    /**
     * @param list<\Pulsar\Database\Introspection\TableInfo> $tables
     */
    private function dropAllTables(array $tables): int
    {
        if ($tables === []) {
            return 0;
        }

        $driver = $this->connection->driver();

        // Disable foreign key checks for clean drops
        match ($driver) {
            Driver::MySQL => $this->connection->execute('SET FOREIGN_KEY_CHECKS = 0'),
            Driver::SQLite => $this->connection->execute('PRAGMA foreign_keys = OFF'),
            Driver::PostgreSQL => null,
        };

        $count = 0;

        foreach ($tables as $table) {
            $quoted = match ($driver) {
                Driver::MySQL => '`' . $table->name . '`',
                Driver::PostgreSQL => '"' . $table->name . '"',
                Driver::SQLite => '"' . $table->name . '"',
            };

            $cascade = $driver === Driver::PostgreSQL ? ' CASCADE' : '';
            $this->connection->execute(sprintf('DROP TABLE IF EXISTS %s%s', $quoted, $cascade));
            ++$count;
        }

        // Re-enable foreign key checks
        match ($driver) {
            Driver::MySQL => $this->connection->execute('SET FOREIGN_KEY_CHECKS = 1'),
            Driver::SQLite => $this->connection->execute('PRAGMA foreign_keys = ON'),
            Driver::PostgreSQL => null,
        };

        return $count;
    }
}
