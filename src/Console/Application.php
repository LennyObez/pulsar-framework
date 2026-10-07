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

use function explode;
use function ksort;
use function mb_strlen;
use function sprintf;
use function str_contains;
use function str_repeat;

/**
 * Console application orchestrator.
 */
#[Internal]
final class Application
{
    private const string NAME = 'Pulsar Framework';

    /** @var array<string, CommandInterface> */
    private array $commands = [];

    /**
     * Lazily-resolvable commands. Each entry is a
     * (name, description, factory) triple. The command is built
     * only when `get($name)` matches its name — never at
     * registration time. Cuts CLI cold-start cost from O(N
     * constructors + dependency walks) to O(1) when running a
     * single command, while keeping help / list output complete
     * via the metadata pair.
     *
     * @var array<string, array{description: string, factory: callable(): CommandInterface}>
     */
    private array $lazyCommands = [];

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
     * Register a command lazily — the factory closure
     * runs only when the command is actually invoked. Pass
     * `$name` and `$description` so help / list output can
     * include the command without instantiating it.
     *
     * @param callable(): CommandInterface $factory
     */
    public function addLazy(string $name, string $description, callable $factory): self
    {
        $this->lazyCommands[$name] = [
            'description' => $description,
            'factory' => $factory,
        ];
        return $this;
    }

    /**
     * Check if a command exists.
     */
    public function has(string $name): bool
    {
        return isset($this->commands[$name]) || isset($this->lazyCommands[$name]);
    }

    /**
     * Get a command by name.
     *
     * When a name is registered lazily and not yet materialised,
     * the factory runs here, the result is memoised in
     * `$this->commands`, and the lazy entry is cleared so a
     * second `get()` call does not double-build.
     *
     * @throws CommandNotFoundException If command not found
     */
    #[NoDiscard]
    public function get(string $name): CommandInterface
    {
        if (isset($this->commands[$name])) {
            return $this->commands[$name];
        }

        if (isset($this->lazyCommands[$name])) {
            $factory = $this->lazyCommands[$name]['factory'];
            $command = $factory();
            $this->commands[$command->name] = $command;
            unset($this->lazyCommands[$name]);
            return $command;
        }

        throw CommandNotFoundException::forCommand($name, $this->findAlternatives($name));
    }

    /**
     * Get all registered commands.
     *
     * This only returns commands that have been materialised —
     * either eagerly via `add()` or because `get($name)` already
     * triggered their factory. Lazy-but-unbuilt entries do NOT
     * appear here, since callers of `all()` (CoreRuntimeProbe,
     * tests) iterate the values as full `CommandInterface` objects
     * and do not tolerate the stub shape. Help / list rendering
     * uses `allDescriptions()` instead so lazy entries still
     * surface to the operator without paying instantiation cost.
     *
     * @return array<string, CommandInterface>
     */
    public function all(): array
    {
        return $this->commands;
    }

    /**
     * Name → description mapping for every registered
     * command, lazy or eager. Used by `renderHelp()` and the
     * list command to render the catalogue without
     * materialising every lazy factory.
     *
     * @return array<string, string>
     */
    public function allDescriptions(): array
    {
        $descriptions = [];
        foreach ($this->commands as $name => $command) {
            $descriptions[$name] = $command->description;
        }
        foreach ($this->lazyCommands as $name => $entry) {
            $descriptions[$name] ??= $entry['description'];
        }
        return $descriptions;
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

        $descriptions = $this->allDescriptions();
        if ($descriptions !== []) {
            $output->writeln('Available commands:');

            // Group by namespace using descriptions: covers both
            // eager and lazy commands without materialising lazy
            // factories.
            $grouped = $this->groupDescriptions($descriptions);

            foreach ($grouped as $namespace => $commands) {
                if ($namespace !== '') {
                    $output->writeln(' ' . $namespace);
                }

                foreach ($commands as $name => $description) {
                    // Pad against the multi-byte character count so
                    // UTF-8 names (accents, CJK) align visually
                    // instead of by raw byte length — sprintf's
                    // `%-20s` counts bytes, which off-sets every
                    // extended-ASCII grapheme by one column.
                    $output->writeln(
                        '  ' . self::padNameForHelp($name, 20) . ' ' . $description,
                    );
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
                    // See renderHelp() for why this is not `%-20s`.
                    $output->writeln(
                        '  ' . self::padNameForHelp($arg['name'], 20) . ' ' . $arg['description'] . ' ' . $required,
                    );
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
     * Group a flat name → description map into namespace
     * buckets. Used by `renderHelp()` to render every command
     * (lazy or eager) without materialising lazy factories.
     *
     * @param array<string, string> $descriptions
     * @return array<string, array<string, string>> namespace → name → description
     */
    private function groupDescriptions(array $descriptions): array
    {
        $grouped = ['' => []];
        foreach ($descriptions as $name => $description) {
            $namespace = str_contains($name, ':') ? explode(':', $name)[0] : '';
            $grouped[$namespace] ??= [];
            $grouped[$namespace][$name] = $description;
        }
        foreach ($grouped as &$bucket) {
            ksort($bucket);
        }
        unset($bucket);
        return $grouped;
    }

    /**
     * Pad a name to a target visual width using the
     * multi-byte character count, not byte count. `sprintf`'s
     * `%-20s` counts bytes, so a name with accents or CJK
     * characters off-sets every multi-byte grapheme by one or
     * more columns and the description column wraps mid-line.
     * `mb_strlen` reports the visual character count under
     * UTF-8, and we top up with spaces — equivalent to PHP 8.3's
     * `mb_str_pad` but without the version-floor dependency.
     */
    private static function padNameForHelp(string $name, int $width): string
    {
        $visibleLength = mb_strlen($name, 'UTF-8');

        if ($visibleLength >= $width) {
            return $name;
        }

        return $name . str_repeat(' ', $width - $visibleLength);
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
