<?php

declare(strict_types=1);

namespace Pulsar\Workflow\Definition;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Defines a single state within a workflow.
 *
 * Each state has a unique name, a type classification (initial, intermediate,
 * or final), and optional metadata for domain-specific extensions
 * (display labels, descriptions, permissions, SLA timers, etc.).
 */
#[Api(since: '1.0.0')]
final readonly class StateDefinition
{
    /**
     * @param array<string, mixed> $metadata Arbitrary domain metadata (labels, descriptions, SLAs, etc.)
     */
    public function __construct(
        public string $name,
        public StateType $type = StateType::Intermediate,
        public array $metadata = [],
    ) {}

    /**
     * Create an initial state (the workflow starting point).
     *
     * @param array<string, mixed> $metadata
     */
    #[NoDiscard]
    public static function initial(string $name, array $metadata = []): self
    {
        return new self(name: $name, type: StateType::Initial, metadata: $metadata);
    }

    /**
     * Create an intermediate state.
     *
     * @param array<string, mixed> $metadata
     */
    #[NoDiscard]
    public static function intermediate(string $name, array $metadata = []): self
    {
        return new self(name: $name, type: StateType::Intermediate, metadata: $metadata);
    }

    /**
     * Create a final (terminal) state.
     *
     * @param array<string, mixed> $metadata
     */
    #[NoDiscard]
    public static function final(string $name, array $metadata = []): self
    {
        return new self(name: $name, type: StateType::Final, metadata: $metadata);
    }

    public function isInitial(): bool
    {
        return $this->type === StateType::Initial;
    }

    public function isFinal(): bool
    {
        return $this->type === StateType::Final;
    }
}
