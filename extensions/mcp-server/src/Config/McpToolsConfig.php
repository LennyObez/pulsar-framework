<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Config;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Support\Coerce;

use function is_array;

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
        /** @var mixed $commandsRaw */
        $commandsRaw = $data['commands'] ?? null;
        if (!is_array($commandsRaw)) {
            $commandsRaw = [];
        }

        return new self(
            disabledReadTools: Coerce::listOfString($data['disabled_read_tools'] ?? null),
            allowedActions: Coerce::listOfString($data['allowed_actions'] ?? null),
            maxOutputBytes: Coerce::strictInt($data['max_output_bytes'] ?? null, 1_048_576),
            actionTimeout: Coerce::strictInt($data['action_timeout'] ?? null, 120),
            commands: [
                'phpunit' => Coerce::nullableString($commandsRaw['phpunit'] ?? null),
                'composer' => Coerce::nullableString($commandsRaw['composer'] ?? null),
                'pnpm' => Coerce::nullableString($commandsRaw['pnpm'] ?? null),
            ],
        );
    }
}
