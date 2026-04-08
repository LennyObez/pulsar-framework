<?php

declare(strict_types=1);

namespace Pulsar\Console;

use Pulsar\Api\Api;

/**
 * Contract for console input.
 * @api
 */
#[Api(since: '1.0.0')]
interface InputInterface
{
    /**
     * The command name from input.
     */
    public ?string $commandName { get; }

    /**
     * All positional arguments.
     *
     * @var array<string|int, mixed>
     */
    public array $arguments { get; }

    /**
     * All options.
     *
     * @var array<string, mixed>
     */
    public array $options { get; }

    /**
     * The raw input tokens.
     *
     * @var list<string>
     */
    public array $tokens { get; }

    /**
     * Get a positional argument by index or name.
     */
    public function getArgument(int|string $key, mixed $default = null): mixed;

    /**
     * Check if an option is present.
     */
    public function hasOption(string $name): bool;

    /**
     * Get an option value.
     */
    public function getOption(string $name, mixed $default = null): mixed;

    /**
     * Get an option value as a string, falling back to the default when not set
     * or when the option value is not a string.
     */
    public function getStringOption(string $name, string $default = ''): string;

    /**
     * Get an option value as a nullable string. Returns null when the option is
     * absent or not a string.
     */
    public function getNullableStringOption(string $name): ?string;

    /**
     * Get an option value as an int, falling back to the default when not set
     * or when the option is not an integer / numeric string.
     */
    public function getIntOption(string $name, int $default = 0): int;

    /**
     * Get an option value as a bool. Returns the default when the option is
     * absent or not a boolean/int/string convertible to one.
     */
    public function getBoolOption(string $name, bool $default = false): bool;
}
