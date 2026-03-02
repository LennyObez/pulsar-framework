<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Config;

use NoDiscard;
use Pulsar\Api\Internal;

use function is_array;
use function is_int;
use function is_string;

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
        $disabledRaw = $data['disabled_read_tools'] ?? [];
        $disabledReadTools = is_array($disabledRaw) ? array_values(array_filter($disabledRaw, '\is_string')) : [];

        $allowedRaw = $data['allowed_actions'] ?? [];
        $allowedActions = is_array($allowedRaw) ? array_values(array_filter($allowedRaw, '\is_string')) : [];

        $maxOutputRaw = $data['max_output_bytes'] ?? null;
        $maxOutputBytes = is_int($maxOutputRaw) ? $maxOutputRaw : 1_048_576;

        $actionTimeoutRaw = $data['action_timeout'] ?? null;
        $actionTimeout = is_int($actionTimeoutRaw) ? $actionTimeoutRaw : 120;

        /** @var array<string, mixed> $commandsRaw */
        $commandsRaw = (array) ($data['commands'] ?? []);

        /** @var string|null $phpunit */
        $phpunit = isset($commandsRaw['phpunit']) && is_string($commandsRaw['phpunit']) ? $commandsRaw['phpunit'] : null;
        /** @var string|null $composer */
        $composer = isset($commandsRaw['composer']) && is_string($commandsRaw['composer']) ? $commandsRaw['composer'] : null;
        /** @var string|null $pnpm */
        $pnpm = isset($commandsRaw['pnpm']) && is_string($commandsRaw['pnpm']) ? $commandsRaw['pnpm'] : null;

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
