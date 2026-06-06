<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\Output\TableFormatter;
use Pulsar\Console\OutputInterface;
use Pulsar\Database\Introspection\DatabaseIntrospectorInterface;

use function count;
use function is_string;
use function sprintf;

/**
 * Display database table structure, columns, and metadata.
 *
 * Usage: db:inspect [table] [--columns] [--all]
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal]
final class DbInspectCommand extends Command
{
    public function __construct(
        private readonly DatabaseIntrospectorInterface $introspector,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'db:inspect';
        $this->description = 'Show database table structure, columns, and metadata';
        $this->addArgument('table', 'Table name to inspect (omit for all tables)');
        $this->addOption('columns', 'Show columns for all tables', 'c');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var mixed $tableName */
        $tableName = $input->getArgument(0);
        $showAllColumns = $input->hasOption('columns');

        if (is_string($tableName) && $tableName !== '') {
            return $this->inspectTable($tableName, $output);
        }

        return $this->listTables($output, $showAllColumns);
    }

    private function listTables(OutputInterface $output, bool $showColumns): int
    {
        $tables = $this->introspector->tables();

        if ($tables === []) {
            $output->writeln('No tables found in the database.');
            return ExitCode::Success->value;
        }

        $output->writeln(sprintf('Database Tables (%d):', count($tables)));
        $output->newLine();

        $table = new TableFormatter();
        $table->setHeaders(['Table', 'Columns', 'Primary Key']);

        foreach ($tables as $tableInfo) {
            $columns = $this->introspector->columns($tableInfo->name);
            $pk = $this->introspector->primaryKey($tableInfo->name);

            $table->addRow([
                $tableInfo->name,
                (string) count($columns),
                $pk ?? '-',
            ]);
        }

        $table->render($output);

        if ($showColumns) {
            $output->newLine();

            foreach ($tables as $tableInfo) {
                $this->renderColumns($tableInfo->name, $output);
                $output->newLine();
            }
        }

        return ExitCode::Success->value;
    }

    private function inspectTable(string $tableName, OutputInterface $output): int
    {
        $columns = $this->introspector->columns($tableName);

        if ($columns === []) {
            $output->errorln(sprintf('Table "%s" not found or has no columns.', $tableName));
            return ExitCode::Error->value;
        }

        $pk = $this->introspector->primaryKey($tableName);

        $output->writeln(sprintf('Table: %s', $tableName));

        if ($pk !== null) {
            $output->writeln(sprintf('Primary Key: %s', $pk));
        }

        $output->writeln(sprintf('Columns: %d', count($columns)));
        $output->newLine();

        $this->renderColumns($tableName, $output);

        return ExitCode::Success->value;
    }

    private function renderColumns(string $tableName, OutputInterface $output): void
    {
        $columns = $this->introspector->columns($tableName);

        $output->writeln(sprintf('  Columns for "%s":', $tableName));

        $table = new TableFormatter();
        $table->setHeaders(['Column', 'Type', 'Nullable', 'Primary Key', 'Default']);

        foreach ($columns as $column) {
            $table->addRow([
                $column->name,
                $column->type,
                $column->nullable ? 'Yes' : 'No',
                $column->isPrimaryKey ? 'Yes' : 'No',
                $column->default ?? '-',
            ]);
        }

        $table->render($output);
    }
}
