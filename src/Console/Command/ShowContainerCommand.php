<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use function assert;
use function count;
use function in_array;
use function is_string;

use JsonException;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\Output\TableFormatter;
use Pulsar\Console\OutputInterface;
use Pulsar\Container\Container;
use Pulsar\Container\Exception\ContainerException;
use Pulsar\Container\Exception\NotFoundException;
use Pulsar\Core\Kernel;
use Pulsar\FeatureFlag\Exception\FeatureFlagException;

use function sprintf;
use function strlen;

/**
 * Displays container bindings.
 */
final class ShowContainerCommand extends Command
{
    public function __construct(
        private readonly Kernel $kernel,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name = 'show:container';
        $this->description = 'Display container bindings';
        $this->addOption('filter', 'Filter by binding ID', 'f');
    }

    /**
     * @throws ContainerException
     * @throws NotFoundException
     * @throws FeatureFlagException
     * @throws JsonException
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->kernel->boot();

        $container = $this->kernel->container();

        assert($container instanceof Container, 'Container introspection requires Pulsar\\Container\\Container.');

        $bindings = $container->getBindings();
        $instances = $container->getInstances();
        $filter = $input->getOption('filter');

        // Apply filter
        if (is_string($filter)) {
            $filterString = $filter;
            $bindings = array_filter($bindings, fn(string $id) => str_contains($id, $filterString));
            $instances = array_filter($instances, fn(string $id) => str_contains($id, $filterString));
        }

        $output->writeln(sprintf('Container Bindings (%d):', count($bindings)));
        $output->newLine();

        if ($bindings !== []) {
            $table = new TableFormatter();
            $table->setHeaders(['ID', 'Type']);

            foreach ($bindings as $id) {
                $type = in_array($id, $instances, true) ? 'Instance' : 'Binding';
                $table->addRow([$this->shortenId($id), $type]);
            }

            $table->render($output);
        } else {
            $output->writeln('  No bindings found.');
        }

        $output->newLine();
        $output->writeln(sprintf('Cached Instances (%d):', count($instances)));
        $output->newLine();

        if ($instances !== []) {
            foreach ($instances as $id) {
                $output->writeln('  - ' . $this->shortenId($id));
            }
        } else {
            $output->writeln('  No cached instances.');
        }

        return ExitCode::Success->value;
    }

    /**
     * Shorten a class name for display.
     */
    private function shortenId(string $id): string
    {
        // If it looks like a class name and is very long, shorten it
        if (strlen($id) > 60 && str_contains($id, '\\')) {
            $parts = explode('\\', $id);
            if (count($parts) > 3) {
                return $parts[0] . '\\...\\' . end($parts);
            }
        }

        return $id;
    }
}
