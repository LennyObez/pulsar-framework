<?php

declare(strict_types=1);

namespace Pulsar\Console\Command\Make;

use Override;
use Pulsar\Api\Api;
use Pulsar\Codegen\Generator\MigrationGenerator;
use Pulsar\Codegen\GeneratorConfig;
use Pulsar\Codegen\PathValidator;
use Pulsar\Codegen\Schema\DiffResult;
use Pulsar\Codegen\Schema\EntityDefinition;
use Pulsar\Codegen\Schema\SchemaDiff;
use Pulsar\Codegen\Schema\SchemaSnapshot;
use Pulsar\Codegen\Schema\SchemaSnapshotStoreInterface;
use Pulsar\Codegen\Template\TemplateRenderer;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

use function count;
use function date;
use function sprintf;

/**
 * Generate migration files from entity mapping metadata changes.
 *
 * Reads the current entity definitions, compares against the stored
 * SchemaSnapshot, computes a DiffResult, and produces migration files.
 * Updates the stored snapshot after generation.
 * @api
 */
#[Api(since: '1.0.0')]
final class MigrationDiffCommand extends Command
{
    public function __construct(
        private readonly SchemaSnapshotStoreInterface $snapshotStore,
        private readonly SchemaDiff $schemaDiff,
        private readonly SchemaSnapshot $currentSnapshot,
        private readonly TemplateRenderer $renderer,
        private readonly PathValidator $pathValidator,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'make:migration-diff';
        $this->description = 'Generate migration from entity mapping metadata changes';
        $this->addOption('path', 'Output base directory', 'p', '.');
        $this->addOption('name', 'Custom migration name');
        $this->addOption('force', 'Overwrite existing migration files');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $basePath = $input->getStringOption('path', '.');
        $customName = $input->getStringOption('name');
        $force = $input->hasOption('force');

        $output->writeln('Comparing schema snapshots...');

        $oldSnapshot = $this->snapshotStore->load();

        if ($oldSnapshot === null) {
            $oldSnapshot = new SchemaSnapshot(entities: [], version: '1');
        }

        $diff = $this->schemaDiff->diff($oldSnapshot, $this->currentSnapshot);

        if (!$diff->hasChanges()) {
            $output->info('No schema changes detected.');
            return ExitCode::Success->value;
        }

        $output->writeln(sprintf('  Found %d operation(s).', count($diff->operations)));
        $output->newLine();

        $timestamp = date('YmdHis');
        $entities = $this->currentSnapshot->entities;
        $generatedCount = 0;

        foreach ($entities as $entity) {
            $entityDiff = $this->filterDiffForEntity($diff, $entity);

            if (!$entityDiff->hasChanges()) {
                continue;
            }

            $migrationName = $customName !== '' ? $customName : $entity->tableName;

            $generator = new MigrationGenerator(
                $this->renderer,
                $this->pathValidator,
                $entityDiff,
                $timestamp,
                $migrationName,
            );

            $config = new GeneratorConfig(
                outputBaseDirectory: $basePath,
                force: $force,
            );

            $result = $generator->generate($entity, $config);

            foreach ($result->files() as $file) {
                $output->writeln(sprintf('  Generated: %s', $file->targetPath));
                ++$generatedCount;
            }
        }

        if ($generatedCount === 0) {
            $output->info('No migration files needed.');
            return ExitCode::Success->value;
        }

        // Update stored snapshot
        $this->snapshotStore->save($this->currentSnapshot);

        $output->newLine();
        $output->success(sprintf(
            'Generated %d migration file%s. Snapshot updated.',
            $generatedCount,
            $generatedCount !== 1 ? 's' : '',
        ));

        return ExitCode::Success->value;
    }

    /**
     * Filter a DiffResult to only include operations for the given entity's table.
     */
    private function filterDiffForEntity(DiffResult $diff, EntityDefinition $entity): DiffResult
    {
        $filtered = [];

        foreach ($diff->operations as $operation) {
            if ($operation->table === $entity->tableName) {
                $filtered[] = $operation;
            }
        }

        return new DiffResult($filtered);
    }
}
