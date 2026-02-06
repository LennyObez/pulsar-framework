<?php

declare(strict_types=1);

namespace Pulsar\Console\Input;

use function array_key_exists;
use function is_int;
use function is_scalar;

use Override;
use Pulsar\Console\InputInterface;

/**
 * Input from an array (useful for testing).
 */
final class ArrayInput implements InputInterface
{
    /** @var list<string> */
    public private(set) array $arguments;

    /** @var array<string, mixed> */
    public private(set) array $options;

    /** @var list<string> */
    public array $tokens {
        get {
            $tokens = [];

            if ($this->commandName !== null) {
                $tokens[] = $this->commandName;
            }

            $tokens = [...$tokens, ...$this->arguments];

            foreach ($this->options as $name => $value) {
                if ($value === true) {
                    $tokens[] = '--' . $name;
                } elseif (is_scalar($value)) {
                    $tokens[] = '--' . $name . '=' . $value;
                }
            }

            return $tokens;
        }
    }

    /**
     * @param string|null $commandName The command to execute
     * @param list<string> $arguments Positional arguments
     * @param array<string, mixed> $options Options (--name=value pairs)
     */
    public function __construct(
        public readonly ?string $commandName = null,
        array $arguments = [],
        array $options = [],
    ) {
        $this->arguments = $arguments;
        $this->options = $options;
    }

    #[Override]
    public function getArgument(int|string $key, mixed $default = null): mixed
    {
        if (is_int($key)) {
            return $this->arguments[$key] ?? $default;
        }

        return $default;
    }

    #[Override]
    public function hasOption(string $name): bool
    {
        return array_key_exists($name, $this->options);
    }

    #[Override]
    public function getOption(string $name, mixed $default = null): mixed
    {
        return $this->options[$name] ?? $default;
    }
}
