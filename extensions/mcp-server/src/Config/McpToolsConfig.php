<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Config;

use NoDiscard;
use Pulsar\Api\Internal;

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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var list<string> $disabledReadTools */
        $disabledReadTools = (array) ($data['disabled_read_tools'] ?? []);

        /** @var list<string> $allowedActions */
        $allowedActions = (array) ($data['allowed_actions'] ?? []);

        $maxOutputBytes = (int) ($data['max_output_bytes'] ?? 1_048_576);

        $actionTimeout = (int) ($data['action_timeout'] ?? 120);

        /** @var array<string, mixed> $commandsRaw */
        $commandsRaw = (array) ($data['commands'] ?? []);

        /** @var ?string $phpunit */
        $phpunit = isset($commandsRaw['phpunit']) ? (string) $commandsRaw['phpunit'] : null;
        /** @var ?string $composer */
        $composer = isset($commandsRaw['composer']) ? (string) $commandsRaw['composer'] : null;
        /** @var ?string $pnpm */
        $pnpm = isset($commandsRaw['pnpm']) ? (string) $commandsRaw['pnpm'] : null;

        return new self(
            disabledReadTools: $disabledReadTools,
            allowedActions: $allowedActions,
            maxOutputBytes: $maxOutputBytes,
            actionTimeout: $actionTimeout,
            commands: [
                'phpunit' => $phpunit,
                'composer' => $composer,
                'pnpm' => $pnpm,
            ],
        );
    }
}
