<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Config;

use NoDiscard;
use Pulsar\Api\Internal;

use function array_filter;
use function array_values;

/**
 * Tool execution sub-configuration.
 */
#[Internal]
final readonly class McpToolsConfig
{
    /**
     * @param list<string> $disabledReadTools Tool names explicitly disabled
     * @param list<string> $allowedActions Action tool names explicitly allowed
     * @param int $maxOutputBytes Maximum bytes in tool output before truncation
     * @param int $actionTimeout Timeout in seconds for action tool execution
     * @param array{phpunit: ?string, composer: ?string, pnpm: ?string} $commands External command paths
     */
    public function __construct(
        public array $disabledReadTools,
        public array $allowedActions,
        public int $maxOutputBytes,
        public int $actionTimeout,
        public array $commands,
    ) {}

    /**
     * @param array{
     *     disabled_read_tools?: list<string>,
     *     allowed_actions?: list<string>,
     *     max_output_bytes?: int,
     *     action_timeout?: int,
     *     commands?: array{phpunit?: string|null, composer?: string|null, pnpm?: string|null},
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $commandsRaw = $data['commands'] ?? [];

        return new self(
            disabledReadTools: array_values(array_filter($data['disabled_read_tools'] ?? [], '\is_string')),
            allowedActions: array_values(array_filter($data['allowed_actions'] ?? [], '\is_string')),
            maxOutputBytes: $data['max_output_bytes'] ?? 1_048_576,
            actionTimeout: $data['action_timeout'] ?? 120,
            commands: [
                'phpunit' => $commandsRaw['phpunit'] ?? null,
                'composer' => $commandsRaw['composer'] ?? null,
                'pnpm' => $commandsRaw['pnpm'] ?? null,
            ],
        );
    }
}
