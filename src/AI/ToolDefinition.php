<?php

declare(strict_types=1);

namespace Pulsar\AI;

use Pulsar\Api\Api;

/**
 * Defines a tool/function that the AI model can call.
 */
#[Api(since: '1.0.0')]
final readonly class ToolDefinition
{
    /**
     * @param string $name Tool/function name
     * @param string $description Human-readable description for the model
     * @param array<string, mixed> $parameters JSON Schema defining the function parameters
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $parameters,
    ) {}
}
