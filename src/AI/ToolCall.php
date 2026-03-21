<?php

declare(strict_types=1);

namespace Pulsar\AI;

use NoDiscard;
use Pulsar\Api\Api;

use function is_array;
use function is_string;

/**
 * Represents a tool/function call requested by an AI model.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ToolCall
{
    /**
     * @param string $id Unique identifier for this tool call
     * @param string $name The function/tool name to invoke
     * @param array<string, mixed> $arguments Parsed arguments for the function
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed> $arguments */
        $arguments = is_array($data['arguments'] ?? null) ? $data['arguments'] : [];

        return new self(
            id: is_string($data['id'] ?? null) ? $data['id'] : '',
            name: is_string($data['name'] ?? null) ? $data['name'] : '',
            arguments: $arguments,
        );
    }
}
