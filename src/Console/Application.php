<?php

declare(strict_types=1);

namespace Pulsar\Console;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Console\Exception\CommandNotFoundException;
use Pulsar\Console\Input\ArgvInput;
use Pulsar\Console\Output\ConsoleOutput;
use Pulsar\Core\Kernel;
use Pulsar\Core\Version;
use Throwable;

use function sprintf;

/**
 * Console application orchestrator.
 */
#[Internal]
final class Application
{
    private const string NAME = 'Pulsar Framework';

    /** @var array<string, CommandInterface> */
    private array $commands = [];

    public function __construct(
        private readonly Kernel $kernel,
    ) {}

    /**
     * Add a command to the application.
     */
    public function add(CommandInterface $command): self
    {
        $this->commands[$command->name] = $command;
        return $this;
    }

    /**
     * Add multiple commands.
     *
     * @param list<CommandInterface> $commands
     */
    public function addCommands(array $commands): self
    {
        foreach ($commands as $command) {
            $this->add($command);
        }
        return $this;
    }

    /**
     * Check if a command exists.
     */
    public function has(string $name): bool
    {
        return isset($this->commands[$name]);
    }

    /**
     * Get a command by name.
     *
     * @throws CommandNotFoundException If command not found
     */
    #[NoDiscard]
    public function get(string $name): CommandInterface
    {
        if (!$this->has($name)) {
            throw CommandNotFoundException::forCommand($name, $this->findAlternatives($name));
        }

        return $this->commands[$name];
    }

    /**
     * Get all registered commands.
     *
     * @return array<string, CommandInterface>
     */
    public function all(): array
    {
        return $this->commands;
    }

    /**
     * Run the application.
     *
     * @param list<string>|null $argv Command line arguments
     * @return int Exit code
     */
    public function run(?array $argv = null): int
    {
        $input = new ArgvInput($argv);
        $output = new ConsoleOutput();

        return $this->doRun($input, $output);
    }

    /**
     * Run with specific input/output (for testing).
     */
    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        // Handle verbosity options
        $this->configureVerbosity($input, $output);

        // Handle --version
        if ($input->hasOption('version') || $input->hasOption('V')) {
            $this->renderVersion($output);
            return ExitCode::Success->value;
        }

        $commandName = $input->commandName;

        // Handle --help or no command
        if ($commandName === null || $input->hasOption('help') || $input->hasOption('h')) {
            if ($commandName !== null && $this->has($commandName)) {
                $this->renderCommandHelp($this->get($commandName), $output);
            } else {
                $this->renderHelp($output);
            }
            return ExitCode::Success->value;
        }

        try {
            $command = $this->get($commandName);

            // Resolve short option flags (-m) to their long names (--method)
            // so commands can look up options by their canonical name.
            if ($command instanceof Command && $input instanceof ArgvInput) {
                $input->resolveShortcuts($command->options);
            }

            return $command->execute($input, $output);
        } catch (CommandNotFoundException $e) {
            $output->errorln($e->getMessage());
            $output->newLine();
            $this->renderHelp($output);
            return ExitCode::Invalid->value;
        } catch (Throwable $e) {
            $output->errorln('Error: ' . $e->getMessage());
            if ($output->isVerbose()) {
                $output->errorln($e->getTraceAsString());
            }
            return ExitCode::Error->value;
        }
    }

    /**
     * Configure verbosity from input options.
     */
    private function configureVerbosity(InputInterface $input, OutputInterface $output): void
    {
        if ($input->hasOption('quiet') || $input->hasOption('q')) {
            $output->verbosity = Verbosity::Quiet;
        } elseif ($input->hasOption('vvv')) {
            $output->verbosity = Verbosity::Debug;
        } elseif ($input->hasOption('vv')) {
            $output->verbosity = Verbosity::Verbose;
        } elseif ($input->hasOption('v') || $input->hasOption('verbose')) {
            $output->verbosity = Verbosity::Verbose;
        }
    }

    /**
     * Render the application version.
     */
    private function renderVersion(OutputInterface $output): void
    {
        $output->writeln(sprintf('%s %s', self::NAME, Version::full()));
    }

    /**
     * Render the main help screen.
     */
    public function renderHelp(OutputInterface $output): void
    {
        $this->renderVersion($output);
        $output->newLine();

        $output->writeln('Usage:');
        $output->writeln('  pulsar <command> [options] [arguments]');
        $output->newLine();

        $output->writeln('Options:');
        $output->writeln('  -h, --help       Display help for a command');
        $output->writeln('  -V, --version    Display the application version');
        $output->writeln('  -q, --quiet      Suppress all output');
        $output->writeln('  -v, --verbose    Increase verbosity (-v, -vv, -vvv)');
        $output->newLine();

        if ($this->commands !== []) {
            $output->writeln('Available commands:');

            // Group commands by namespace
            $grouped = $this->groupCommands();

            foreach ($grouped as $namespace => $commands) {
                if ($namespace !== '') {
                    $output->writeln(' ' . $namespace);
                }

                foreach ($commands as $command) {
                    $output->writeln(sprintf(
                        '  %-20s %s',
                        $command->name,
                        $command->description,
                    ));
                }
            }
        }
    }

    /**
     * Render help for a specific command.
     */
    private function renderCommandHelp(CommandInterface $command, OutputInterface $output): void
    {
        $output->writeln('Description:');
        $output->writeln('  ' . $command->description);
        $output->newLine();

        $output->writeln('Usage:');
        if ($command instanceof Command) {
            $output->writeln('  ' . $command->getUsage());
        } else {
            $output->writeln('  ' . $command->name);
        }
        $output->newLine();

        if ($command instanceof Command) {
            $arguments = $command->arguments;
            if ($arguments !== []) {
                $output->writeln('Arguments:');
                foreach ($arguments as $arg) {
                    $required = $arg['required'] ? '(required)' : '(optional)';
                    $output->writeln(sprintf('  %-20s %s %s', $arg['name'], $arg['description'], $required));
                }
                $output->newLine();
            }

            $options = $command->options;
            if ($options !== []) {
                $output->writeln('Options:');
                foreach ($options as $name => $config) {
                    $shortcut = $config['shortcut'] !== null ? '-' . $config['shortcut'] . ', ' : '    ';
                    $output->writeln(sprintf('  %s--%-15s %s', $shortcut, $name, $config['description']));
                }
                $output->newLine();
            }
        }
    }

    /**
     * Group commands by namespace.
     *
     * @return array<string, list<CommandInterface>>
     */
    private function groupCommands(): array
    {
        $grouped = ['' => []];

        foreach ($this->commands as $command) {
            $name = $command->name;
            $namespace = str_contains($name, ':') ? explode(':', $name)[0] : '';

            $grouped[$namespace] ??= [];
            $grouped[$namespace][] = $command;
        }

        // Sort commands within each namespace
        foreach ($grouped as &$commands) {
            usort($commands, fn(CommandInterface $a, CommandInterface $b) => strcmp($a->name, $b->name));
        }

        return $grouped;
    }

    /**
     * Find alternative command names for a given input.
     *
     * @return list<string>
     */
    private function findAlternatives(string $name): array
    {
        $alternatives = [];
        $threshold = 3; // Levenshtein distance threshold

        foreach (array_keys($this->commands) as $commandName) {
            $distance = levenshtein($name, $commandName);
            if ($distance <= $threshold) {
                $alternatives[] = $commandName;
            }
        }

        return $alternatives;
    }

    /**
     * Get the kernel instance.
     */
    public function kernel(): Kernel
    {
        return $this->kernel;
    }
}
