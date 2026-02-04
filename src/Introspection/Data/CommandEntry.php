<?php

declare(strict_types=1);

namespace Pulsar\Introspection\Data;

use Pulsar\Api\Api;

/**
 * Describes a single CLI command and its signature (arguments + options).
 */
#[Api(since: '1.0.0')]
final readonly class CommandEntry
{
    /**
     * @param list<array{name: string, description: string, required: bool}>                    $arguments
     * @param array<string, array{description: string, shortcut: string|null, default: mixed}> $options
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $arguments = [],
        public array $options = [],
    ) {}

    /**
     * @return array{name: string, description: string, arguments: list<array{name: string, description: string, required: bool}>, options: array<string, array{description: string, shortcut: string|null, default: mixed}>}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'arguments' => $this->arguments,
            'options' => $this->options,
        ];
    }
}
