<?php

declare(strict_types=1);

namespace Pulsar\Console\Input;

use Override;
use Pulsar\Console\InputInterface;

use function is_bool;
use function is_int;
use function is_numeric;
use function is_string;
use function strlen;

/**
 * Input parsed from command line arguments (argv).
 */
final class ArgvInput implements InputInterface
{
    /** @var list<string> */
    public private(set) array $tokens;

    public private(set) ?string $commandName = null;

    /** @var list<string> */
    public private(set) array $arguments = [];

    /** @var array<string, string|bool> */
    public private(set) array $options = [];

    /**
     * @param list<string>|null $argv Command line arguments (null to use $_SERVER['argv'])
     */
    public function __construct(?array $argv = null)
    {
        if ($argv === null) {
            /** @var list<string> $serverArgv */
            $serverArgv = $_SERVER['argv'] ?? [];
            $argv = $serverArgv;
        }

        // Remove script name
        array_shift($argv);

        $this->tokens = $argv;
        $this->parse();
    }

    /**
     * Parse the input tokens.
     *
     * Options may receive a value via `--name=value` or `--name value` syntax.
     * When no `=` is present the parser peeks at the next token: if it exists
     * and does not start with `-` it is consumed as the option's value. This
     * matches the behaviour of most CLI tools (getopt, Symfony Console, etc.).
     */
    private function parse(): void
    {
        $tokens = $this->tokens;

        while ($tokens !== []) {
            $token = array_shift($tokens);

            if (str_starts_with($token, '--')) {
                $this->parseLongOption($token, $tokens);
            } elseif (str_starts_with($token, '-') && $token !== '-') {
                $this->parseShortOption($token, $tokens);
            } elseif ($this->commandName === null) {
                $this->commandName = $token;
            } else {
                $this->arguments[] = $token;
            }
        }
    }

    /**
     * Parse a long option (--name, --name=value, or --name value).
     *
     * @param list<string> $remaining Remaining tokens (passed by reference so
     *                                the next token can be consumed as a value).
     */
    private function parseLongOption(string $token, array &$remaining): void
    {
        $name = substr($token, 2);

        if (str_contains($name, '=')) {
            $parts = explode('=', $name, 2);
            $this->options[$parts[0]] = $parts[1] ?? '';
        } elseif ($remaining !== [] && !str_starts_with($remaining[0], '-')) {
            // Next token is a non-option value; consume it.
            $this->options[$name] = array_shift($remaining);
        } else {
            $this->options[$name] = true;
        }
    }

    /**
     * Parse a short option (-n, -n=value, -n value, or -abc as boolean flags).
     *
     * @param list<string> $remaining Remaining tokens (passed by reference so
     *                                the next token can be consumed as a value).
     */
    private function parseShortOption(string $token, array &$remaining): void
    {
        $chars = substr($token, 1);

        // Handle -n=value
        if (str_contains($chars, '=')) {
            $parts = explode('=', $chars, 2);
            $this->options[$parts[0]] = $parts[1] ?? '';
            return;
        }

        // Single character: peek at next token for a value (-m POST).
        if (strlen($chars) === 1) {
            if ($remaining !== [] && !str_starts_with($remaining[0], '-')) {
                $this->options[$chars] = array_shift($remaining);
            } else {
                $this->options[$chars] = true;
            }
            return;
        }

        // Multiple characters: treat each as a boolean flag (-abc → -a -b -c).
        for ($i = 0; $i < strlen($chars); $i++) {
            $this->options[$chars[$i]] = true;
        }
    }

    /**
     * Resolve short option names to their canonical long names.
     *
     * Call this after the target command is known so that shortcut mappings
     * (e.g. `-m` to `method`) are applied to the parsed options array.
     *
     * @param array<string, array{shortcut: string|null, ...}> $definitions
     *        Option definitions from the resolved command.
     */
    public function resolveShortcuts(array $definitions): void
    {
        foreach ($definitions as $longName => $config) {
            $shortcut = $config['shortcut'] ?? null;
            if ($shortcut !== null && isset($this->options[$shortcut]) && !isset($this->options[$longName])) {
                $this->options[$longName] = $this->options[$shortcut];
                unset($this->options[$shortcut]);
            }
        }
    }

    #[Override]
    public function getArgument(int|string $key, mixed $default = null): mixed
    {
        if (is_int($key)) {
            return $this->arguments[$key] ?? $default;
        }

        // Named arguments not supported in this simple implementation
        return $default;
    }

    #[Override]
    public function hasOption(string $name): bool
    {
        return isset($this->options[$name]);
    }

    #[Override]
    public function getOption(string $name, mixed $default = null): mixed
    {
        return $this->options[$name] ?? $default;
    }

    #[Override]
    public function getStringOption(string $name, string $default = ''): string
    {
        $value = $this->options[$name] ?? null;

        return is_string($value) ? $value : $default;
    }

    #[Override]
    public function getNullableStringOption(string $name): ?string
    {
        $value = $this->options[$name] ?? null;

        return is_string($value) ? $value : null;
    }

    #[Override]
    public function getIntOption(string $name, int $default = 0): int
    {
        $value = $this->options[$name] ?? null;

        return is_string($value) && is_numeric($value) ? (int) $value : $default;
    }

    #[Override]
    public function getBoolOption(string $name, bool $default = false): bool
    {
        $value = $this->options[$name] ?? null;

        return is_bool($value) ? $value : $default;
    }
}
