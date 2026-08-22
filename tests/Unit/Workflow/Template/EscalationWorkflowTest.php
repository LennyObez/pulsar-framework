<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Workflow\Template;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Workflow\Definition\WorkflowType;
use Pulsar\Workflow\Guard\RoleGuard;
use Pulsar\Workflow\Template\EscalationWorkflow;

#[CoversClass(EscalationWorkflow::class)]
final class EscalationWorkflowTest extends TestCase
{
    #[Test]
    public function test_build_produces_valid_definition(): void
    {
        $definition = EscalationWorkflow::build();

        self::assertSame('escalation', $definition->name);
        self::assertSame(WorkflowType::StateMachine, $definition->type);
    }

    #[Test]
    public function test_initial_state_is_submitted(): void
    {
        $definition = EscalationWorkflow::build();

        self::assertSame('submitted', $definition->getInitialState()->name);
    }

    #[Test]
    public function test_default_has_three_levels(): void
    {
        $definition = EscalationWorkflow::build();

        self::assertTrue($definition->hasState('level_1'));
        self::assertTrue($definition->hasState('level_2'));
        self::assertTrue($definition->hasState('level_3'));
    }

    #[Test]
    public function test_approved_and_rejected_are_final(): void
    {
        $definition = EscalationWorkflow::build();

        self::assertTrue($definition->getState('approved')->isFinal());
        self::assertTrue($definition->getState('rejected')->isFinal());
    }

    #[Test]
    public function test_each_level_has_approve_and_reject_transitions(): void
    {
        $definition = EscalationWorkflow::build();

        for ($i = 1; $i <= 3; $i++) {
            self::assertTrue($definition->hasTransition("approve_level_{$i}"));
            self::assertTrue($definition->hasTransition("reject_level_{$i}"));
        }
    }

    #[Test]
    public function test_first_level_transition_is_assign(): void
    {
        $definition = EscalationWorkflow::build();

        self::assertTrue($definition->hasTransition('assign'));
        $transition = $definition->getTransition('assign');
        self::assertSame(['submitted'], $transition->froms);
        self::assertSame('level_1', $transition->to);
    }

    #[Test]
    public function test_escalation_transitions_chain_levels(): void
    {
        $definition = EscalationWorkflow::build();

        $escalate2 = $definition->getTransition('escalate_to_2');
        self::assertSame(['level_1'], $escalate2->froms);
        self::assertSame('level_2', $escalate2->to);

        $escalate3 = $definition->getTransition('escalate_to_3');
        self::assertSame(['level_2'], $escalate3->froms);
        self::assertSame('level_3', $escalate3->to);
    }

    #[Test]
    public function test_roles_per_level_are_applied(): void
    {
        $definition = EscalationWorkflow::build(
            rolesPerLevel: [['junior'], ['senior'], ['cto']],
        );

        $approve1 = $definition->getTransition('approve_level_1');
        self::assertSame(['junior'], $approve1->metadata['required_roles']);

        $approve2 = $definition->getTransition('approve_level_2');
        self::assertSame(['senior'], $approve2->metadata['required_roles']);

        $approve3 = $definition->getTransition('approve_level_3');
        self::assertSame(['cto'], $approve3->metadata['required_roles']);
    }

    #[Test]
    public function test_custom_number_of_levels(): void
    {
        $definition = EscalationWorkflow::build(levels: 2, rolesPerLevel: [['a'], ['b']]);

        self::assertTrue($definition->hasState('level_1'));
        self::assertTrue($definition->hasState('level_2'));
        self::assertFalse($definition->hasState('level_3'));
    }

    #[Test]
    public function test_approve_reject_transitions_have_role_guard(): void
    {
        $definition = EscalationWorkflow::build();

        $approve = $definition->getTransition('approve_level_1');
        self::assertContains(RoleGuard::class, $approve->guards);

        $reject = $definition->getTransition('reject_level_1');
        self::assertContains(RoleGuard::class, $reject->guards);
    }

    #[Test]
    public function test_metadata_marks_template_as_escalation(): void
    {
        $definition = EscalationWorkflow::build();

        self::assertSame('escalation', $definition->metadata['template']);
        self::assertTrue($definition->metadata['regulated']);
        self::assertSame(3, $definition->metadata['levels']);
    }
}
