<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Workflow\Definition;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Workflow\Definition\DefinitionBuilder;
use Pulsar\Workflow\Definition\StateDefinition;
use Pulsar\Workflow\Definition\StateType;
use Pulsar\Workflow\Definition\TransitionDefinition;
use Pulsar\Workflow\Definition\WorkflowDefinition;
use Pulsar\Workflow\Definition\WorkflowType;
use Pulsar\Workflow\Exception\WorkflowException;
use Pulsar\Workflow\Guard\RoleGuard;

#[CoversClass(DefinitionBuilder::class)]
final class DefinitionBuilderTest extends TestCase
{
    #[Test]
    public function test_build_creates_valid_definition(): void
    {
        $definition = DefinitionBuilder::create('order')
            ->initialState('draft')
            ->state('review')
            ->finalState('done')
            ->transition('submit', 'draft', 'review')
            ->transition('complete', 'review', 'done')
            ->build();

        self::assertInstanceOf(WorkflowDefinition::class, $definition);
        self::assertSame('order', $definition->name);
    }

    #[Test]
    public function test_type_sets_workflow_type(): void
    {
        $definition = DefinitionBuilder::create('parallel')
            ->type(WorkflowType::Workflow)
            ->initialState('start')
            ->finalState('end')
            ->transition('go', 'start', 'end')
            ->build();

        self::assertSame(WorkflowType::Workflow, $definition->type);
    }

    #[Test]
    public function test_defaults_to_state_machine_type(): void
    {
        $definition = DefinitionBuilder::create('sm')
            ->initialState('start')
            ->finalState('end')
            ->transition('go', 'start', 'end')
            ->build();

        self::assertSame(WorkflowType::StateMachine, $definition->type);
    }

    #[Test]
    public function test_initial_state_creates_initial_type(): void
    {
        $definition = DefinitionBuilder::create('test')
            ->initialState('begin')
            ->finalState('end')
            ->transition('go', 'begin', 'end')
            ->build();

        $state = $definition->getState('begin');

        self::assertSame(StateType::Initial, $state->type);
    }

    #[Test]
    public function test_state_creates_intermediate_type(): void
    {
        $definition = DefinitionBuilder::create('test')
            ->initialState('start')
            ->state('middle')
            ->finalState('end')
            ->transition('step1', 'start', 'middle')
            ->transition('step2', 'middle', 'end')
            ->build();

        $state = $definition->getState('middle');

        self::assertSame(StateType::Intermediate, $state->type);
    }

    #[Test]
    public function test_final_state_creates_final_type(): void
    {
        $definition = DefinitionBuilder::create('test')
            ->initialState('start')
            ->finalState('end')
            ->transition('go', 'start', 'end')
            ->build();

        $state = $definition->getState('end');

        self::assertSame(StateType::Final, $state->type);
    }

    #[Test]
    public function test_add_state_accepts_state_definition_directly(): void
    {
        $stateDefinition = StateDefinition::intermediate('custom');

        $definition = DefinitionBuilder::create('test')
            ->initialState('start')
            ->addState($stateDefinition)
            ->finalState('end')
            ->transition('go', 'start', 'custom')
            ->transition('finish', 'custom', 'end')
            ->build();

        self::assertTrue($definition->hasState('custom'));
    }

    #[Test]
    public function test_transition_creates_single_from_transition(): void
    {
        $definition = DefinitionBuilder::create('test')
            ->initialState('start')
            ->finalState('end')
            ->transition('go', 'start', 'end')
            ->build();

        $transition = $definition->getTransition('go');

        self::assertSame(['start'], $transition->froms);
        self::assertSame('end', $transition->to);
    }

    #[Test]
    public function test_join_transition_creates_multi_from_transition(): void
    {
        $definition = DefinitionBuilder::create('parallel')
            ->type(WorkflowType::Workflow)
            ->initialState('start')
            ->state('branch_a')
            ->state('branch_b')
            ->finalState('joined')
            ->transition('fork_a', 'start', 'branch_a')
            ->transition('fork_b', 'start', 'branch_b')
            ->joinTransition('merge', ['branch_a', 'branch_b'], 'joined')
            ->build();

        $transition = $definition->getTransition('merge');

        self::assertSame(['branch_a', 'branch_b'], $transition->froms);
        self::assertSame('joined', $transition->to);
    }

    #[Test]
    public function test_add_transition_accepts_transition_definition_directly(): void
    {
        $transitionDef = TransitionDefinition::create('custom', 'start', 'end');

        $definition = DefinitionBuilder::create('test')
            ->initialState('start')
            ->finalState('end')
            ->addTransition($transitionDef)
            ->build();

        self::assertTrue($definition->hasTransition('custom'));
    }

    #[Test]
    public function test_transition_with_guards(): void
    {
        $definition = DefinitionBuilder::create('test')
            ->initialState('start')
            ->finalState('end')
            ->transition('go', 'start', 'end', guards: [RoleGuard::class])
            ->build();

        $transition = $definition->getTransition('go');

        self::assertSame([RoleGuard::class], $transition->guards);
    }

    #[Test]
    public function test_transition_with_metadata(): void
    {
        $definition = DefinitionBuilder::create('test')
            ->initialState('start')
            ->finalState('end')
            ->transition('go', 'start', 'end', metadata: ['required_roles' => ['admin']])
            ->build();

        $transition = $definition->getTransition('go');

        self::assertSame(['required_roles' => ['admin']], $transition->metadata);
    }

    #[Test]
    public function test_metadata_is_stored_on_definition(): void
    {
        $definition = DefinitionBuilder::create('test')
            ->metadata(['domain' => 'banking', 'regulated' => true])
            ->initialState('start')
            ->finalState('end')
            ->transition('go', 'start', 'end')
            ->build();

        self::assertSame(['domain' => 'banking', 'regulated' => true], $definition->metadata);
    }

    #[Test]
    public function test_state_with_metadata(): void
    {
        $definition = DefinitionBuilder::create('test')
            ->initialState('start', ['label' => 'Begin'])
            ->finalState('end', ['label' => 'Complete'])
            ->transition('go', 'start', 'end')
            ->build();

        self::assertSame(['label' => 'Begin'], $definition->getState('start')->metadata);
        self::assertSame(['label' => 'Complete'], $definition->getState('end')->metadata);
    }

    #[Test]
    public function test_build_validates_definition(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessageIsOrContains('no initial state defined');

        (void) DefinitionBuilder::create('bad')
            ->state('middle')
            ->finalState('end')
            ->transition('go', 'middle', 'end')
            ->build();
    }

    #[Test]
    public function test_build_rejects_definition_without_final_state(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessageIsOrContains('no final state defined');

        (void) DefinitionBuilder::create('bad')
            ->initialState('start')
            ->state('middle')
            ->transition('go', 'start', 'middle')
            ->build();
    }
}
