<?php

declare(strict_types=1);

namespace Pulsar\Console\Input;

use function is_int;

use Pulsar\Console\InputInterface;

use function strlen;

/**
 * Input parsed from command line arguments (argv).
 */
final class ArgvInput implements InputInterface
{
    /** @var list<string> */
    private array $tokens;

    private ?string $commandName = null;

    /** @var list<string> */
    private array $arguments = [];

    /** @var array<string, string|bool> */
    private array $options = [];

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
     */
    private function parse(): void
    {
        $tokens = $this->tokens;

        while ($tokens !== []) {
            $token = array_shift($tokens);

            if (str_starts_with($token, '--')) {
                $this->parseLongOption($token);
            } elseif (str_starts_with($token, '-') && $token !== '-') {
                $this->parseShortOption($token);
            } elseif ($this->commandName === null) {
                $this->commandName = $token;
            } else {
                $this->arguments[] = $token;
            }
        }
    }

    /**
     * Parse a long option (--name or --name=value).
     */
    private function parseLongOption(string $token): void
    {
        $name = substr($token, 2);

        if (str_contains($name, '=')) {
            $parts = explode('=', $name, 2);
            $this->options[$parts[0]] = $parts[1] ?? '';
        } else {
            $this->options[$name] = true;
        }
    }

    /**
     * Parse a short option (-n or -nvalue).
     */
    private function parseShortOption(string $token): void
    {
        $chars = substr($token, 1);

        // Handle -abc as -a -b -c (boolean flags)
        // Handle -n=value
        if (str_contains($chars, '=')) {
            $parts = explode('=', $chars, 2);
            $this->options[$parts[0]] = $parts[1] ?? '';
            return;
        }

        // Treat each character as a boolean flag
        for ($i = 0; $i < strlen($chars); $i++) {
            $this->options[$chars[$i]] = true;
        }
    }

    public function getCommandName(): ?string
    {
        return $this->commandName;
    }

    public function getArgument(int|string $key, mixed $default = null): mixed
    {
        if (is_int($key)) {
            return $this->arguments[$key] ?? $default;
        }

        // Named arguments not supported in this simple implementation
        return $default;
    }

    public function getArguments(): array
    {
        return $this->arguments;
    }

    public function hasOption(string $name): bool
    {
        return isset($this->options[$name]);
    }

    public function getOption(string $name, mixed $default = null): mixed
    {
        return $this->options[$name] ?? $default;
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function getTokens(): array
    {
        return $this->tokens;
    }
}
