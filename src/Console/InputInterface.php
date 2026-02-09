<?php

declare(strict_types=1);

namespace Pulsar\Console;

use Pulsar\Api\Api;

/**
 * Contract for console input.
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
}
