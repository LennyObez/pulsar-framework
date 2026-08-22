<?php

declare(strict_types=1);

namespace Pulsar\Console\Command\Make;

use Override;
use Pulsar\Api\Api;
use Pulsar\Codegen\GeneratorConfig;
use Pulsar\Codegen\GeneratorInterface;
use Pulsar\Codegen\Schema\EntityDefinition;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

use function array_filter;
use function array_keys;
use function array_values;
use function count;
use function explode;
use function in_array;
use function is_string;
use function sprintf;
use function trim;

/**
 * Generate full CRUD stack from an entity definition.
 *
 * Orchestrates all available generators (repository, migration, validation,
 * form, API resource, admin resource, test factory, policy) to produce
 * a complete CRUD scaffold for an entity.
 * @api
 */
#[Api(since: '1.0.0')]
final class CrudCommand extends Command
{
    /** @var array<string, GeneratorInterface> */
    private readonly array $generators;

    /**
     * @param array<string, GeneratorInterface> $generators Named generator map (e.g., 'repository' => $gen)
     * @param EntityDefinition $entity The entity to generate CRUD for
     */
    public function __construct(
        array $generators,
        private readonly EntityDefinition $entity,
    ) {
        $this->generators = $generators;
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'make:crud';
        $this->description = 'Generate full CRUD stack for an entity';
        $this->addArgument('entity', 'Entity class name', true);
        $this->addOption('path', 'Output base directory', 'p', 'src');
        $this->addOption('namespace', 'Namespace prefix', 'n', 'App');
        $this->addOption('force', 'Overwrite existing files');
        $this->addOption('skip', 'Comma-separated generators to skip (e.g., admin,form)', 's');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $entityName = $input->getArgument(0);

        if (!is_string($entityName) || $entityName === '') {
            $output->errorln('Entity name is required.');
            return ExitCode::Invalid->value;
        }

        $basePath = $input->getStringOption('path', 'src');
        $namespace = $input->getStringOption('namespace', 'App');
        $force = $input->hasOption('force');
        $skip = $this->parseSkipList($input->getStringOption('skip'));

        if ($this->generators === []) {
            $output->errorln('No generators registered.');
            return ExitCode::Error->value;
        }

        $output->writeln(sprintf('Generating CRUD for %s...', $entityName));
        $output->newLine();

        $config = new GeneratorConfig(
            outputBaseDirectory: $basePath,
            namespacePrefix: $namespace,
            force: $force,
        );

        $totalFiles = 0;
        $skippedGenerators = 0;
        /** @var list<string> $conflicts */
        $conflicts = [];

        foreach ($this->generators as $name => $generator) {
            if (in_array($name, $skip, true)) {
                $output->writeln(sprintf('  [skip] %s', $name));
                ++$skippedGenerators;
                continue;
            }

            $result = $generator->generate($this->entity, $config);
            $files = $result->files();

            if ($files === []) {
                continue;
            }

            foreach ($files as $file) {
                $output->writeln(sprintf('  [%s] %s', $name, $file->targetPath));
                ++$totalFiles;
            }

            // Collect conflicts
            foreach ($result->conflicts() as $conflict) {
                $conflicts[] = $conflict->targetPath;
            }
        }

        $output->newLine();

        if ($conflicts !== []) {
            $output->warning(sprintf(
                '%d file%s already exist%s:',
                count($conflicts),
                count($conflicts) !== 1 ? 's' : '',
                count($conflicts) === 1 ? 's' : '',
            ));
            foreach ($conflicts as $path) {
                $output->writeln(sprintf('  ! %s', $path));
            }
            $output->newLine();
        }

        $availableGenerators = array_keys($this->generators);
        $activeGenerators = count($availableGenerators) - $skippedGenerators;

        $output->success(sprintf(
            'Generated %d file%s from %d generator%s.',
            $totalFiles,
            $totalFiles !== 1 ? 's' : '',
            $activeGenerators,
            $activeGenerators !== 1 ? 's' : '',
        ));

        if ($skippedGenerators > 0) {
            $output->info(sprintf('  Skipped %d generator%s.', $skippedGenerators, $skippedGenerators !== 1 ? 's' : ''));
        }

        return ExitCode::Success->value;
    }

    /**
     * Parse the --skip option value into a list of generator names.
     *
     * @return list<string>
     */
    private function parseSkipList(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(
            array_map(
                static fn(string $item): string => trim($item),
                explode(',', $raw),
            ),
            static fn(string $item): bool => $item !== '',
        ));
    }
}
