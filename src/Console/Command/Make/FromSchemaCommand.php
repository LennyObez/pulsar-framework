<?php

declare(strict_types=1);

namespace Pulsar\Console\Command\Make;

use Override;
use Pulsar\Api\Api;
use Pulsar\Codegen\Schema\EntityDefinition;
use Pulsar\Codegen\Schema\IdentifierNormalizer;
use Pulsar\Codegen\Schema\SchemaSnapshot;
use Pulsar\Codegen\Schema\SchemaSnapshotStoreInterface;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Database\Introspection\DatabaseIntrospectorInterface;

use function file_exists;
use function file_put_contents;
use function implode;
use function is_dir;
use function is_string;
use function json_encode;
use function mkdir;
use function sprintf;

use const DIRECTORY_SEPARATOR;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Import existing database tables into entity definition JSON files.
 *
 * Uses DatabaseIntrospector to read the schema and IdentifierNormalizer
 * for safe PHP identifiers. Writes entity definition JSON files and
 * updates the schema snapshot for future diff-based migration generation.
 */
#[Api(since: '1.0.0')]
final class FromSchemaCommand extends Command
{
    public function __construct(
        private readonly DatabaseIntrospectorInterface $introspector,
        private readonly SchemaSnapshotStoreInterface $snapshotStore,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'make:from-schema';
        $this->description = 'Import database tables into entity definitions';
        $this->addOption('path', 'Output base directory', 'p', 'src');
        $this->addOption('namespace', 'Entity namespace prefix', 'n', 'App\\Entity');
        $this->addOption('table', 'Import only the specified table', 't');
        $this->addOption('force', 'Overwrite existing files');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $basePath = $input->getOption('path', 'src');
        $namespace = $input->getOption('namespace', 'App\\Entity');
        $force = $input->hasOption('force');

        if (!is_string($basePath)) {
            $basePath = 'src';
        }

        if (!is_string($namespace)) {
            $namespace = 'App\\Entity';
        }

        $specificTable = $input->getOption('table');
        if (!is_string($specificTable) || $specificTable === '') {
            $specificTable = null;
        }

        $tables = $this->introspector->tables();

        if ($tables === []) {
            $output->warning('No tables found in the database.');
            return ExitCode::Success->value;
        }

        // Filter to specific table if requested
        if ($specificTable !== null) {
            $found = false;
            foreach ($tables as $table) {
                if ($table->name === $specificTable) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $output->errorln(sprintf('Table "%s" not found in the database.', $specificTable));
                return ExitCode::Error->value;
            }
        }

        $output->writeln('Importing database schema...');
        $output->newLine();

        $entityDir = $basePath . DIRECTORY_SEPARATOR . 'Entity';
        if (!is_dir($entityDir)) {
            mkdir($entityDir, 0o755, true);
        }

        /** @var array<string, EntityDefinition> $entities */
        $entities = [];
        $generated = 0;
        $skipped = 0;

        foreach ($tables as $table) {
            if ($specificTable !== null && $table->name !== $specificTable) {
                continue;
            }

            $columns = $this->introspector->columns($table->name);

            if ($columns === []) {
                $output->warning(sprintf('Table "%s" has no columns, skipping.', $table->name));
                continue;
            }

            $primaryKey = $this->introspector->primaryKey($table->name) ?? 'id';

            $entity = EntityDefinition::fromDatabaseColumns(
                tableName: $table->name,
                columns: $columns,
                primaryKey: $primaryKey,
                namespace: $namespace,
            );

            $className = IdentifierNormalizer::toClassName($table->name);
            $filePath = $entityDir . DIRECTORY_SEPARATOR . $className . '.json';

            // Check for existing file
            if (file_exists($filePath) && !$force) {
                $output->warning(sprintf('  Skipped %s (already exists, use --force to overwrite)', $className . '.json'));
                ++$skipped;
                $entities[$table->name] = $entity;
                continue;
            }

            $json = json_encode(
                $entity->toArray(),
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );

            file_put_contents($filePath, $json . "\n");

            $output->writeln(sprintf(
                '  %s -> %s\\%s (%s)',
                $table->name,
                $namespace,
                $className,
                $className . '.json',
            ));

            $entities[$table->name] = $entity;
            ++$generated;
        }

        // Save schema snapshot for future diff comparisons
        if ($entities !== []) {
            $snapshot = new SchemaSnapshot(entities: $entities, version: '1');
            $this->snapshotStore->save($snapshot);
            $output->writeln('  Updated schema snapshot.');
        }

        $output->newLine();

        $parts = [];
        if ($generated > 0) {
            $parts[] = sprintf('%d imported', $generated);
        }
        if ($skipped > 0) {
            $parts[] = sprintf('%d skipped', $skipped);
        }

        $total = $generated + $skipped;

        if ($total === 0) {
            $output->warning('No tables could be imported (all had empty column sets).');

            return ExitCode::Success->value;
        }

        $output->success(sprintf(
            '%d table%s processed (%s).',
            $total,
            $total !== 1 ? 's' : '',
            implode(', ', $parts),
        ));

        return ExitCode::Success->value;
    }
}
