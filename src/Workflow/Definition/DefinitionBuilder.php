<?php

declare(strict_types=1);

namespace Pulsar\Workflow\Definition;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Workflow\Exception\WorkflowException;

/**
 * Fluent builder for constructing validated workflow definitions.
 *
 * Usage:
 *
 *     $definition = DefinitionBuilder::create('order_process')
 *         ->type(WorkflowType::StateMachine)
 *         ->initialState('draft')
 *         ->state('pending_review')
 *         ->state('approved')
 *         ->finalState('completed')
 *         ->finalState('rejected')
 *         ->transition('submit', 'draft', 'pending_review')
 *         ->transition('approve', 'pending_review', 'approved')
 *         ->transition('reject', 'pending_review', 'rejected')
 *         ->transition('complete', 'approved', 'completed')
 *         ->build();
 */
#[Api(since: '1.0.0')]
final class DefinitionBuilder
{
    /** @var array<string, StateDefinition> */
    private array $states = [];

    /** @var array<string, TransitionDefinition> */
    private array $transitions = [];

    private WorkflowType $type = WorkflowType::StateMachine;

    /** @var array<string, mixed> */
    private array $metadata = [];

    /** @param non-empty-string $name */
    private function __construct(
        private readonly string $name,
    ) {}

    /**
     * Start building a new workflow definition.
     *
     * @param non-empty-string $name
     */
    #[NoDiscard]
    public static function create(string $name): self
    {
        return new self($name);
    }

    /**
     * Set the workflow type (StateMachine or Workflow).
     */
    public function type(WorkflowType $type): self
    {
        $this->type = $type;

        return $this;
    }

    /**
     * Add an initial state (the workflow starting point).
     *
     * @param array<string, mixed> $metadata
     */
    public function initialState(string $name, array $metadata = []): self
    {
        $this->states[$name] = StateDefinition::initial($name, $metadata);

        return $this;
    }

    /**
     * Add an intermediate state.
     *
     * @param array<string, mixed> $metadata
     */
    public function state(string $name, array $metadata = []): self
    {
        $this->states[$name] = StateDefinition::intermediate($name, $metadata);

        return $this;
    }

    /**
     * Add a final (terminal) state.
     *
     * @param array<string, mixed> $metadata
     */
    public function finalState(string $name, array $metadata = []): self
    {
        $this->states[$name] = StateDefinition::final($name, $metadata);

        return $this;
    }

    /**
     * Add a state definition directly.
     */
    public function addState(StateDefinition $stateDefinition): self
    {
        $this->states[$stateDefinition->name] = $stateDefinition;

        return $this;
    }

    /**
     * Add a transition from a single source state.
     *
     * @param list<class-string>   $guards
     * @param array<string, mixed> $metadata
     */
    public function transition(
        string $name,
        string $from,
        string $to,
        array $guards = [],
        array $metadata = [],
    ): self {
        $this->transitions[$name] = TransitionDefinition::create(
            name: $name,
            from: $from,
            to: $to,
            guards: $guards,
            metadata: $metadata,
        );

        return $this;
    }

    /**
     * Add a transition from multiple source states (join semantics in Workflow mode).
     *
     * @param non-empty-list<string> $froms
     * @param list<class-string>     $guards
     * @param array<string, mixed>   $metadata
     */
    public function joinTransition(
        string $name,
        array $froms,
        string $to,
        array $guards = [],
        array $metadata = [],
    ): self {
        $this->transitions[$name] = new TransitionDefinition(
            name: $name,
            froms: $froms,
            to: $to,
            guards: $guards,
            metadata: $metadata,
        );

        return $this;
    }

    /**
     * Add a transition definition directly.
     */
    public function addTransition(TransitionDefinition $transitionDefinition): self
    {
        $this->transitions[$transitionDefinition->name] = $transitionDefinition;

        return $this;
    }

    /**
     * Set arbitrary metadata on the definition.
     *
     * @param array<string, mixed> $metadata
     */
    public function metadata(array $metadata): self
    {
        $this->metadata = $metadata;

        return $this;
    }

    /**
     * Build and validate the workflow definition.
     *
     * @throws WorkflowException If the definition is structurally invalid
     */
    #[NoDiscard]
    public function build(): WorkflowDefinition
    {
        $definition = new WorkflowDefinition(
            name: $this->name,
            states: $this->states,
            transitions: $this->transitions,
            type: $this->type,
            metadata: $this->metadata,
        );

        $definition->validate();

        return $definition;
    }
}
