<?php

declare(strict_types=1);

namespace Pulsar\Console\Input;

use function array_key_exists;
use function is_int;
use function is_scalar;

use Pulsar\Console\InputInterface;

/**
 * Input from an array (useful for testing).
 */
final class ArrayInput implements InputInterface
{
    /** @var list<string> */
    private array $arguments;

    /** @var array<string, mixed> */
    private array $options;

    /**
     * @param string|null $commandName The command to execute
     * @param list<string> $arguments Positional arguments
     * @param array<string, mixed> $options Options (--name=value pairs)
     */
    public function __construct(
        private readonly ?string $commandName = null,
        array $arguments = [],
        array $options = [],
    ) {
        $this->arguments = $arguments;
        $this->options = $options;
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

        return $default;
    }

    public function getArguments(): array
    {
        return $this->arguments;
    }

    public function hasOption(string $name): bool
    {
        return array_key_exists($name, $this->options);
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
        $tokens = [];

        if ($this->commandName !== null) {
            $tokens[] = $this->commandName;
        }

        $tokens = [...$tokens, ...$this->arguments];

        foreach ($this->options as $name => $value) {
            if ($value === true) {
                $tokens[] = '--' . $name;
            } elseif (is_scalar($value)) {
                /** @var int|float|string|false $value */
                $tokens[] = '--' . $name . '=' . (string) $value;
            }
        }

        return $tokens;
    }
}
