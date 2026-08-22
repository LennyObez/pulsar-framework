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
use Pulsar\Container\AdvancedContainerInterface;
use Pulsar\Container\Scope\ScopeWideningException;
use Pulsar\Core\KernelInterface;

use function array_filter;
use function count;
use function sprintf;
use function str_contains;

use const ARRAY_FILTER_USE_KEY;

/**
 * Debug container bindings, lifetimes, and scope validation.
 *
 * Provides three modes of operation:
 *  - Default: lists all bindings with lifetime, lazy flag, tag count, and decorator count.
 *  - --check-lifetimes: validates scope graph for widening violations.
 *  - --tags: displays all tagged services grouped by tag name.
 *
 * Usage: debug:container [--check-lifetimes] [--tags] [--filter=<pattern>]
 */
#[Internal]
final class DebugContainerCommand extends Command
{
    public function __construct(
        private readonly KernelInterface $kernel,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'debug:container';
        $this->description = 'Debug container bindings, lifetimes, and scope validation';
        $this->addOption('check-lifetimes', 'Validate scope graph for lifetime widening violations', 'l');
        $this->addOption('filter', 'Filter by binding ID', 'f');
        $this->addOption('tags', 'Show all tagged services grouped by tag name', 't');
    }

    /**
     * @throws ScopeWideningException If --check-lifetimes detects a scope widening violation
     */
    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->kernel->boot();

        $container = $this->kernel->container();
        $filter = $input->getNullableStringOption('filter');

        if ($input->hasOption('check-lifetimes')) {
            return $this->checkLifetimes($container, $output);
        }

        if ($input->hasOption('tags')) {
            return $this->showTags($container, $filter, $output);
        }

        return $this->showBindings($container, $filter, $output);
    }

    /**
     * Validate the scope graph for lifetime widening violations.
     *
     * @param mixed $container The booted container instance
     */
    private function checkLifetimes(mixed $container, OutputInterface $output): int
    {
        if (!$container instanceof AdvancedContainerInterface) {
            $output->error('Container does not support advanced features (definitions unavailable).');
            return ExitCode::Error->value;
        }

        try {
            $container->validateScopeGraph();
        } catch (ScopeWideningException $e) {
            $output->errorln('Scope widening violation detected:');
            $output->newLine();
            $output->errorln(sprintf('  %s', $e->getMessage()));
            $output->newLine();

            return ExitCode::Error->value;
        }

        $output->success(sprintf(
            'No scope widening violations found (%d definitions checked).',
            count($container->getDefinitions()),
        ));

        return ExitCode::Success->value;
    }

    /**
     * Display all tagged services grouped by tag name.
     *
     * @param mixed $container The booted container instance
     */
    private function showTags(mixed $container, ?string $filter, OutputInterface $output): int
    {
        if (!$container instanceof AdvancedContainerInterface) {
            $output->error('Container does not support advanced features (definitions unavailable).');
            return ExitCode::Error->value;
        }

        $definitions = $container->getDefinitions();

        $tagGroups = [];

        foreach ($definitions as $id => $definition) {
            if ($filter !== null && !str_contains($id, $filter)) {
                continue;
            }

            foreach ($definition->tags as $tag) {
                $tagGroups[$tag->name][] = [
                    'id' => $id,
                    'priority' => $tag->priority,
                ];
            }
        }

        if ($tagGroups === []) {
            $output->writeln('No tagged services found.');
            return ExitCode::Success->value;
        }

        $output->writeln(sprintf('Tagged Services (%d tags):', count($tagGroups)));
        $output->newLine();

        foreach ($tagGroups as $tagName => $services) {
            $output->info(sprintf('%s (%d services)', $tagName, count($services)));

            $table = new TableFormatter();
            $table->setHeaders(['Service ID', 'Priority']);

            foreach ($services as $service) {
                $table->addRow([$service['id'], (string) $service['priority']]);
            }

            $table->render($output);
            $output->newLine();
        }

        return ExitCode::Success->value;
    }

    /**
     * Display all bindings with their metadata.
     *
     * @param mixed $container The booted container instance
     */
    private function showBindings(mixed $container, ?string $filter, OutputInterface $output): int
    {
        if (!$container instanceof AdvancedContainerInterface) {
            $output->error('Container does not support advanced features (definitions unavailable).');
            return ExitCode::Error->value;
        }

        $definitions = $container->getDefinitions();

        if ($filter !== null) {
            $definitions = array_filter(
                $definitions,
                static fn(string $id): bool => str_contains($id, $filter),
                ARRAY_FILTER_USE_KEY,
            );
        }

        $output->writeln(sprintf('Container Bindings (%d):', count($definitions)));
        $output->newLine();

        if ($definitions === []) {
            $output->writeln('  No bindings found.');
            return ExitCode::Success->value;
        }

        $table = new TableFormatter();
        $table->setHeaders(['ID', 'Lifetime', 'Lazy', 'Tags', 'Decorators']);

        foreach ($definitions as $id => $definition) {
            $table->addRow([
                $id,
                $definition->lifetime->value,
                $definition->lazy ? 'Yes' : 'No',
                (string) count($definition->tags),
                (string) count($definition->decorators),
            ]);
        }

        $table->render($output);

        return ExitCode::Success->value;
    }
}
