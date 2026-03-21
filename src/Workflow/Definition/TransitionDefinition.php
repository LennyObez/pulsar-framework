<?php

declare(strict_types=1);

namespace Pulsar\Workflow\Definition;

use NoDiscard;
use Pulsar\Api\Api;

use function in_array;

/**
 * Defines a named transition between states in a workflow.
 *
 * A transition specifies one or more source states, a single target state,
 * optional guard class references that must all pass before the transition
 * is allowed, and arbitrary metadata for domain-specific extensions.
 *
 * In StateMachine mode, exactly one `from` state is typical. In Workflow mode,
 * multiple `from` states enable join semantics (all source branches must be
 * active before the transition fires).
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class TransitionDefinition
{
    /**
     * @param non-empty-list<string>   $froms      Source state names (at least one required)
     * @param list<class-string>       $guards     Guard class references evaluated before transition
     * @param array<string, mixed>     $metadata   Arbitrary domain metadata
     */
    public function __construct(
        public string $name,
        public array $froms,
        public string $to,
        public array $guards = [],
        public array $metadata = [],
    ) {}

    /**
     * Create a transition from a single source state.
     *
     * @param list<class-string>   $guards
     * @param array<string, mixed> $metadata
     */
    #[NoDiscard]
    public static function create(
        string $name,
        string $from,
        string $to,
        array $guards = [],
        array $metadata = [],
    ): self {
        return new self(
            name: $name,
            froms: [$from],
            to: $to,
            guards: $guards,
            metadata: $metadata,
        );
    }

    /**
     * Check if this transition can originate from the given state.
     */
    public function canTransitionFrom(string $stateName): bool
    {
        return in_array($stateName, $this->froms, true);
    }
}
