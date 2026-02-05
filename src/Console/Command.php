<?php

declare(strict_types=1);

namespace Pulsar\Console;

use function sprintf;

/**
 * Abstract base class for console commands.
 *
 * Provides common functionality and a structured approach to command implementation.
 */
abstract class Command implements CommandInterface
{
    public protected(set) string $name = '';
    public protected(set) string $description = '';

    /** @var list<array{name: string, description: string, required: bool}> */
    public protected(set) array $arguments = [];

    /** @var array<string, array{description: string, shortcut: string|null, default: mixed}> */
    public protected(set) array $options = [];

    public function __construct()
    {
        $this->configure();
    }

    /**
     * Configure the command (name, description, arguments, options).
     */
    protected function configure(): void
    {
        // Override in subclasses
    }

    /**
     * Add an argument definition.
     */
    protected function addArgument(string $name, string $description = '', bool $required = false): self
    {
        $this->arguments[] = [
            'name' => $name,
            'description' => $description,
            'required' => $required,
        ];
        return $this;
    }

    /**
     * Add an option definition.
     */
    protected function addOption(
        string $name,
        string $description = '',
        ?string $shortcut = null,
        ?string $default = null,
    ): self {
        $this->options[$name] = [
            'description' => $description,
            'shortcut' => $shortcut,
            'default' => $default,
        ];
        return $this;
    }

    /**
     * Get the usage string for this command.
     */
    public function getUsage(): string
    {
        $usage = $this->name;

        foreach ($this->options as $name => $config) {
            $usage .= sprintf(' [--%s]', $name);
        }

        foreach ($this->arguments as $arg) {
            if ($arg['required']) {
                $usage .= sprintf(' <%s>', $arg['name']);
            } else {
                $usage .= sprintf(' [%s]', $arg['name']);
            }
        }

        return $usage;
    }
}
