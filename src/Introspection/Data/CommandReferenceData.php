<?php

declare(strict_types=1);

namespace Pulsar\Introspection\Data;

use Pulsar\Api\Api;

use function array_map;

/**
 * Complete CLI command reference for the application.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class CommandReferenceData
{
    /**
     * @param list<CommandEntry> $commands
     */
    public function __construct(
        public array $commands = [],
    ) {}

    /**
     * @return array{commands: list<array{name: string, description: string, arguments: list<array{name: string, description: string, required: bool}>, options: array<string, array{description: string, shortcut: string|null, default: mixed}>}>}
     */
    public function toArray(): array
    {
        return [
            'commands' => array_map(
                static fn(CommandEntry $c): array => $c->toArray(),
                $this->commands,
            ),
        ];
    }
}
