<?php

declare(strict_types=1);

namespace Pulsar\Console;

/**
 * Contract for console input.
 */
interface InputInterface
{
    /**
     * Get the command name from input.
     */
    public function getCommandName(): ?string;

    /**
     * Get a positional argument by index or name.
     */
    public function getArgument(int|string $key, mixed $default = null): mixed;

    /**
     * Get all arguments.
     *
     * @return array<string|int, mixed>
     */
    public function getArguments(): array;

    /**
     * Check if an option is present.
     */
    public function hasOption(string $name): bool;

    /**
     * Get an option value.
     */
    public function getOption(string $name, mixed $default = null): mixed;

    /**
     * Get all options.
     *
     * @return array<string, mixed>
     */
    public function getOptions(): array;

    /**
     * Get the raw input tokens.
     *
     * @return list<string>
     */
    public function getTokens(): array;
}
