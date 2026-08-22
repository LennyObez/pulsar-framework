<?php

declare(strict_types=1);

namespace Pulsar\Workflow\Definition;

use Pulsar\Api\Api;
use Pulsar\Workflow\Exception\WorkflowException;

use function array_diff;
use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_values;
use function count;
use function implode;
use function sprintf;

/**
 * Top-level workflow definition containing states, transitions, and metadata.
 *
 * Supports two modes via {@see WorkflowType}:
 * - StateMachine: exactly one active state at any time
 * - Workflow: multiple states may be active concurrently (parallel branches)
 *
 * Definitions are immutable once constructed. Use {@see DefinitionBuilder}
 * for fluent construction with validation.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class WorkflowDefinition
{
    /** @var array<string, StateDefinition> States indexed by name */
    public array $states;

    /** @var array<string, TransitionDefinition> Transitions indexed by name */
    public array $transitions;

    /**
     * @param non-empty-string                     $name
     * @param array<string, StateDefinition>       $states      States indexed by name
     * @param array<string, TransitionDefinition>  $transitions Transitions indexed by name
     * @param array<string, mixed>                 $metadata    Arbitrary domain metadata
     */
    public function __construct(
        public string $name,
        array $states,
        array $transitions,
        public WorkflowType $type = WorkflowType::StateMachine,
        public array $metadata = [],
    ) {
        $this->states = $states;
        $this->transitions = $transitions;
    }

    /**
     * Get the single initial state for this definition.
     *
     * @throws WorkflowException If no initial state exists
     */
    public function getInitialState(): StateDefinition
    {
        foreach ($this->states as $state) {
            if ($state->isInitial()) {
                return $state;
            }
        }

        throw WorkflowException::invalidDefinition($this->name, 'no initial state defined');
    }

    /**
     * Get a state by name.
     *
     * @throws WorkflowException If the state does not exist
     */
    public function getState(string $name): StateDefinition
    {
        if (!array_key_exists($name, $this->states)) {
            throw WorkflowException::invalidState($name, $this->name);
        }

        return $this->states[$name];
    }

    /**
     * Check if a state exists in this definition.
     */
    public function hasState(string $name): bool
    {
        return array_key_exists($name, $this->states);
    }

    /**
     * Get a transition by name.
     *
     * @throws WorkflowException If the transition does not exist
     */
    public function getTransition(string $name): TransitionDefinition
    {
        if (!array_key_exists($name, $this->transitions)) {
            throw WorkflowException::invalidTransition($name, '(any)', $this->name);
        }

        return $this->transitions[$name];
    }

    /**
     * Check if a transition exists in this definition.
     */
    public function hasTransition(string $name): bool
    {
        return array_key_exists($name, $this->transitions);
    }

    /**
     * Get all transitions that can originate from the given state.
     *
     * @return list<TransitionDefinition>
     */
    public function getTransitionsFrom(string $stateName): array
    {
        return array_values(array_filter(
            $this->transitions,
            static fn(TransitionDefinition $t): bool => $t->canTransitionFrom($stateName),
        ));
    }

    /**
     * Get all final (terminal) states.
     *
     * @return list<StateDefinition>
     */
    public function getFinalStates(): array
    {
        return array_values(array_filter(
            $this->states,
            static fn(StateDefinition $s): bool => $s->isFinal(),
        ));
    }

    /**
     * Get all state names.
     *
     * @return list<string>
     */
    public function getStateNames(): array
    {
        return array_keys($this->states);
    }

    /**
     * Get all transition names.
     *
     * @return list<string>
     */
    public function getTransitionNames(): array
    {
        return array_keys($this->transitions);
    }

    /**
     * Validate the structural integrity of this definition.
     *
     * Checks:
     * - Exactly one initial state exists
     * - At least one final state exists
     * - All transition source/target states reference existing states
     * - No transitions originate from final states
     *
     * @throws WorkflowException On first validation failure
     */
    public function validate(): void
    {
        $initialStates = array_filter(
            $this->states,
            static fn(StateDefinition $s): bool => $s->isInitial(),
        );

        if (count($initialStates) === 0) {
            throw WorkflowException::invalidDefinition($this->name, 'no initial state defined');
        }

        if (count($initialStates) > 1) {
            throw WorkflowException::invalidDefinition(
                $this->name,
                sprintf('multiple initial states defined: %s', implode(', ', array_keys($initialStates))),
            );
        }

        $finalStates = $this->getFinalStates();

        if (count($finalStates) === 0) {
            throw WorkflowException::invalidDefinition($this->name, 'no final state defined');
        }

        foreach ($this->transitions as $transition) {
            foreach ($transition->froms as $from) {
                if (!$this->hasState($from)) {
                    throw WorkflowException::invalidDefinition(
                        $this->name,
                        sprintf('transition "%s" references unknown source state "%s"', $transition->name, $from),
                    );
                }

                $fromState = $this->states[$from];

                if ($fromState->isFinal()) {
                    throw WorkflowException::transitionFromFinalState($from, $this->name);
                }
            }

            if (!$this->hasState($transition->to)) {
                throw WorkflowException::invalidDefinition(
                    $this->name,
                    sprintf('transition "%s" references unknown target state "%s"', $transition->name, $transition->to),
                );
            }
        }

        $this->validateReachability();
    }

    /**
     * Verify that all non-initial states are reachable from the initial state
     * and that at least one final state is reachable.
     *
     * Uses BFS from the initial state following transition edges.
     */
    private function validateReachability(): void
    {
        $initialState = $this->getInitialState();
        $reachable = [$initialState->name => true];
        $queue = [$initialState->name];

        while ($queue !== []) {
            $current = array_shift($queue);

            foreach ($this->transitions as $transition) {
                if (!$transition->canTransitionFrom($current)) {
                    continue;
                }

                if (!isset($reachable[$transition->to])) {
                    $reachable[$transition->to] = true;
                    $queue[] = $transition->to;
                }
            }
        }

        $allStateNames = array_keys($this->states);
        $unreachable = array_diff($allStateNames, array_keys($reachable));

        if ($unreachable !== []) {
            throw WorkflowException::invalidDefinition(
                $this->name,
                sprintf('unreachable states detected: %s', implode(', ', $unreachable)),
            );
        }

        $reachableFinalStates = array_filter(
            $this->states,
            static fn(StateDefinition $s): bool => $s->isFinal() && isset($reachable[$s->name]),
        );

        if (count($reachableFinalStates) === 0) {
            throw WorkflowException::invalidDefinition(
                $this->name,
                'no final state is reachable from the initial state',
            );
        }
    }
}
