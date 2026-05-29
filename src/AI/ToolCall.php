<?php

declare(strict_types=1);

namespace Pulsar\AI;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

use function is_array;

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
        $arguments = $data['arguments'] ?? null;
        /** @var array<string, mixed> $argumentsArr */
        $argumentsArr = is_array($arguments) ? $arguments : [];

        return new self(
            id: Coerce::string($data['id'] ?? null),
            name: Coerce::string($data['name'] ?? null),
            arguments: $argumentsArr,
        );
    }
}
