<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use JsonException;
use Pulsar\Console\Application;
use Pulsar\Console\Command;
use Pulsar\Console\CommandInterface;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

use function sprintf;

/**
 * Lists all available commands.
 */
final class ListCommand extends Command
{
    public function __construct(
        private readonly Application $application,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name = 'list';
        $this->description = 'List all available commands';
        $this->addOption('format', 'Output format (text, json)', 'f', 'text');
    }

    /**
     * @throws JsonException
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = $input->getOption('format', 'text');

        if ($format === 'json') {
            return $this->renderJson($output);
        }

        return $this->renderText($output);
    }

    private function renderText(OutputInterface $output): int
    {
        $commands = $this->application->all();

        if ($commands === []) {
            $output->writeln('No commands available.');
            return ExitCode::Success->value;
        }

        $output->writeln('Available commands:');
        $output->newLine();

        // Group commands by namespace
        $grouped = $this->groupCommands($commands);

        foreach ($grouped as $namespace => $namespaceCommands) {
            if ($namespace !== '') {
                $output->info(' ' . $namespace);
            }

            foreach ($namespaceCommands as $command) {
                $output->writeln(sprintf(
                    '  %-24s %s',
                    $command->name,
                    $command->description,
                ));
            }
            $output->newLine();
        }

        return ExitCode::Success->value;
    }

    /**
     * @throws JsonException
     */
    private function renderJson(OutputInterface $output): int
    {
        $commands = [];

        foreach ($this->application->all() as $command) {
            $name = $command->name;
            $namespace = str_contains($name, ':') ? explode(':', $name)[0] : '';

            $commands[] = [
                'name' => $name,
                'namespace' => $namespace,
                'description' => $command->description,
            ];
        }

        $output->writeln(json_encode($commands, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        return ExitCode::Success->value;
    }

    /**
     * @param array<string, CommandInterface> $commands
     * @return array<string, list<CommandInterface>>
     */
    private function groupCommands(array $commands): array
    {
        $grouped = ['' => []];

        foreach ($commands as $command) {
            $name = $command->name;
            $namespace = str_contains($name, ':') ? explode(':', $name)[0] : '';

            $grouped[$namespace] ??= [];
            $grouped[$namespace][] = $command;
        }

        // Sort commands within each namespace
        foreach ($grouped as &$cmds) {
            usort($cmds, fn(CommandInterface $a, CommandInterface $b) => strcmp($a->name, $b->name));
        }

        return $grouped;
    }
}
