<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Workflow\Definition;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Workflow\Definition\StateDefinition;
use Pulsar\Workflow\Definition\TransitionDefinition;
use Pulsar\Workflow\Definition\WorkflowDefinition;
use Pulsar\Workflow\Definition\WorkflowType;
use Pulsar\Workflow\Exception\WorkflowException;

#[CoversClass(WorkflowDefinition::class)]
final class WorkflowDefinitionTest extends TestCase
{
    #[Test]
    public function test_constructor_stores_name_and_type(): void
    {
        $definition = $this->buildSimpleDefinition();

        self::assertSame('test_workflow', $definition->name);
        self::assertSame(WorkflowType::StateMachine, $definition->type);
    }

    #[Test]
    public function test_constructor_defaults_to_state_machine_type(): void
    {
        $definition = new WorkflowDefinition(
            name: 'test',
            states: ['start' => StateDefinition::initial('start'), 'end' => StateDefinition::final('end')],
            transitions: ['go' => TransitionDefinition::create('go', 'start', 'end')],
        );

        self::assertSame(WorkflowType::StateMachine, $definition->type);
    }

    #[Test]
    public function test_workflow_type_can_be_set(): void
    {
        $definition = new WorkflowDefinition(
            name: 'test',
            states: ['start' => StateDefinition::initial('start'), 'end' => StateDefinition::final('end')],
            transitions: ['go' => TransitionDefinition::create('go', 'start', 'end')],
            type: WorkflowType::Workflow,
        );

        self::assertSame(WorkflowType::Workflow, $definition->type);
    }

    #[Test]
    public function test_get_initial_state_returns_initial_state(): void
    {
        $definition = $this->buildSimpleDefinition();
        $initial = $definition->getInitialState();

        self::assertSame('draft', $initial->name);
        self::assertTrue($initial->isInitial());
    }

    #[Test]
    public function test_get_initial_state_throws_when_no_initial(): void
    {
        $definition = new WorkflowDefinition(
            name: 'bad',
            states: ['end' => StateDefinition::final('end')],
            transitions: [],
        );

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('no initial state defined');

        $definition->getInitialState();
    }

    #[Test]
    public function test_get_state_returns_existing_state(): void
    {
        $definition = $this->buildSimpleDefinition();
        $state = $definition->getState('draft');

        self::assertSame('draft', $state->name);
    }

    #[Test]
    public function test_get_state_throws_for_unknown_state(): void
    {
        $definition = $this->buildSimpleDefinition();

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('does not exist');

        $definition->getState('nonexistent');
    }

    #[Test]
    public function test_has_state_returns_true_for_existing(): void
    {
        $definition = $this->buildSimpleDefinition();

        self::assertTrue($definition->hasState('draft'));
    }

    #[Test]
    public function test_has_state_returns_false_for_unknown(): void
    {
        $definition = $this->buildSimpleDefinition();

        self::assertFalse($definition->hasState('nonexistent'));
    }

    #[Test]
    public function test_get_transition_returns_existing_transition(): void
    {
        $definition = $this->buildSimpleDefinition();
        $transition = $definition->getTransition('submit');

        self::assertSame('submit', $transition->name);
    }

    #[Test]
    public function test_get_transition_throws_for_unknown(): void
    {
        $definition = $this->buildSimpleDefinition();

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('not valid');

        $definition->getTransition('nonexistent');
    }

    #[Test]
    public function test_has_transition_returns_true_for_existing(): void
    {
        $definition = $this->buildSimpleDefinition();

        self::assertTrue($definition->hasTransition('submit'));
    }

    #[Test]
    public function test_has_transition_returns_false_for_unknown(): void
    {
        $definition = $this->buildSimpleDefinition();

        self::assertFalse($definition->hasTransition('nonexistent'));
    }

    #[Test]
    public function test_get_transitions_from_returns_matching_transitions(): void
    {
        $definition = $this->buildSimpleDefinition();
        $transitions = $definition->getTransitionsFrom('draft');

        self::assertCount(1, $transitions);
        self::assertSame('submit', $transitions[0]->name);
    }

    #[Test]
    public function test_get_transitions_from_returns_empty_for_final_state(): void
    {
        $definition = $this->buildSimpleDefinition();
        $transitions = $definition->getTransitionsFrom('completed');

        self::assertSame([], $transitions);
    }

    #[Test]
    public function test_get_final_states_returns_only_final(): void
    {
        $definition = $this->buildSimpleDefinition();
        $finals = $definition->getFinalStates();

        self::assertCount(1, $finals);
        self::assertSame('completed', $finals[0]->name);
    }

    #[Test]
    public function test_get_state_names_returns_all_names(): void
    {
        $definition = $this->buildSimpleDefinition();
        $names = $definition->getStateNames();

        self::assertSame(['draft', 'review', 'completed'], $names);
    }

    #[Test]
    public function test_get_transition_names_returns_all_names(): void
    {
        $definition = $this->buildSimpleDefinition();
        $names = $definition->getTransitionNames();

        self::assertSame(['submit', 'complete'], $names);
    }

    #[Test]
    public function test_metadata_is_stored(): void
    {
        $definition = new WorkflowDefinition(
            name: 'test',
            states: ['s' => StateDefinition::initial('s'), 'e' => StateDefinition::final('e')],
            transitions: ['t' => TransitionDefinition::create('t', 's', 'e')],
            metadata: ['domain' => 'finance'],
        );

        self::assertSame(['domain' => 'finance'], $definition->metadata);
    }

    #[Test]
    public function test_validate_passes_for_valid_definition(): void
    {
        $this->expectNotToPerformAssertions();

        $definition = $this->buildSimpleDefinition();

        $definition->validate();
    }

    #[Test]
    public function test_validate_throws_when_no_initial_state(): void
    {
        $definition = new WorkflowDefinition(
            name: 'bad',
            states: [
                'mid' => StateDefinition::intermediate('mid'),
                'end' => StateDefinition::final('end'),
            ],
            transitions: [],
        );

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('no initial state defined');

        $definition->validate();
    }

    #[Test]
    public function test_validate_throws_when_multiple_initial_states(): void
    {
        $definition = new WorkflowDefinition(
            name: 'bad',
            states: [
                'start1' => StateDefinition::initial('start1'),
                'start2' => StateDefinition::initial('start2'),
                'end' => StateDefinition::final('end'),
            ],
            transitions: [],
        );

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('multiple initial states');

        $definition->validate();
    }

    #[Test]
    public function test_validate_throws_when_no_final_state(): void
    {
        $definition = new WorkflowDefinition(
            name: 'bad',
            states: [
                'start' => StateDefinition::initial('start'),
                'mid' => StateDefinition::intermediate('mid'),
            ],
            transitions: [],
        );

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('no final state defined');

        $definition->validate();
    }

    #[Test]
    public function test_validate_throws_when_transition_references_unknown_source(): void
    {
        $definition = new WorkflowDefinition(
            name: 'bad',
            states: [
                'start' => StateDefinition::initial('start'),
                'end' => StateDefinition::final('end'),
            ],
            transitions: [
                'go' => TransitionDefinition::create('go', 'nonexistent', 'end'),
            ],
        );

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('unknown source state');

        $definition->validate();
    }

    #[Test]
    public function test_validate_throws_when_transition_references_unknown_target(): void
    {
        $definition = new WorkflowDefinition(
            name: 'bad',
            states: [
                'start' => StateDefinition::initial('start'),
                'end' => StateDefinition::final('end'),
            ],
            transitions: [
                'go' => TransitionDefinition::create('go', 'start', 'nonexistent'),
            ],
        );

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('unknown target state');

        $definition->validate();
    }

    #[Test]
    public function test_validate_throws_when_transition_from_final_state(): void
    {
        $definition = new WorkflowDefinition(
            name: 'bad',
            states: [
                'start' => StateDefinition::initial('start'),
                'end' => StateDefinition::final('end'),
            ],
            transitions: [
                'go' => TransitionDefinition::create('go', 'start', 'end'),
                'leave' => TransitionDefinition::create('leave', 'end', 'start'),
            ],
        );

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('final state');

        $definition->validate();
    }

    #[Test]
    public function test_validate_throws_when_state_is_unreachable(): void
    {
        $definition = new WorkflowDefinition(
            name: 'bad',
            states: [
                'start' => StateDefinition::initial('start'),
                'reachable' => StateDefinition::intermediate('reachable'),
                'orphaned' => StateDefinition::intermediate('orphaned'),
                'end' => StateDefinition::final('end'),
            ],
            transitions: [
                'go' => TransitionDefinition::create('go', 'start', 'reachable'),
                'finish' => TransitionDefinition::create('finish', 'reachable', 'end'),
            ],
        );

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('unreachable states detected: orphaned');

        $definition->validate();
    }

    #[Test]
    public function test_validate_throws_when_no_final_state_is_reachable(): void
    {
        // All states reachable but cycles with no path to a final state
        $definition = new WorkflowDefinition(
            name: 'bad',
            states: [
                'start' => StateDefinition::initial('start'),
                'middle' => StateDefinition::intermediate('middle'),
                'unreachable_end' => StateDefinition::final('unreachable_end'),
            ],
            transitions: [
                'go' => TransitionDefinition::create('go', 'start', 'middle'),
                'back' => TransitionDefinition::create('back', 'middle', 'start'),
            ],
        );

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('unreachable states detected');

        $definition->validate();
    }

    #[Test]
    public function test_validate_passes_with_multiple_paths_to_final(): void
    {
        $this->expectNotToPerformAssertions();

        $definition = new WorkflowDefinition(
            name: 'ok',
            states: [
                'start' => StateDefinition::initial('start'),
                'path_a' => StateDefinition::intermediate('path_a'),
                'path_b' => StateDefinition::intermediate('path_b'),
                'end' => StateDefinition::final('end'),
            ],
            transitions: [
                'take_a' => TransitionDefinition::create('take_a', 'start', 'path_a'),
                'take_b' => TransitionDefinition::create('take_b', 'start', 'path_b'),
                'finish_a' => TransitionDefinition::create('finish_a', 'path_a', 'end'),
                'finish_b' => TransitionDefinition::create('finish_b', 'path_b', 'end'),
            ],
        );

        $definition->validate();
    }

    private function buildSimpleDefinition(): WorkflowDefinition
    {
        return new WorkflowDefinition(
            name: 'test_workflow',
            states: [
                'draft' => StateDefinition::initial('draft'),
                'review' => StateDefinition::intermediate('review'),
                'completed' => StateDefinition::final('completed'),
            ],
            transitions: [
                'submit' => TransitionDefinition::create('submit', 'draft', 'review'),
                'complete' => TransitionDefinition::create('complete', 'review', 'completed'),
            ],
        );
    }
}
