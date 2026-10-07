<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Workflow\Template;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Workflow\Definition\WorkflowType;
use Pulsar\Workflow\Guard\RoleGuard;
use Pulsar\Workflow\Template\ApprovalWorkflow;

#[CoversClass(ApprovalWorkflow::class)]
final class ApprovalWorkflowTest extends TestCase
{
    #[Test]
    public function test_build_produces_valid_definition(): void
    {
        $definition = ApprovalWorkflow::build();

        self::assertSame('approval', $definition->name);
        self::assertSame(WorkflowType::StateMachine, $definition->type);
    }

    #[Test]
    public function test_build_has_correct_states(): void
    {
        $definition = ApprovalWorkflow::build();

        self::assertTrue($definition->hasState('draft'));
        self::assertTrue($definition->hasState('pending_review'));
        self::assertTrue($definition->hasState('approved'));
        self::assertTrue($definition->hasState('rejected'));
    }

    #[Test]
    public function test_initial_state_is_draft(): void
    {
        $definition = ApprovalWorkflow::build();
        $initial = $definition->getInitialState();

        self::assertSame('draft', $initial->name);
    }

    #[Test]
    public function test_approved_and_rejected_are_final_states(): void
    {
        $definition = ApprovalWorkflow::build();

        self::assertTrue($definition->getState('approved')->isFinal());
        self::assertTrue($definition->getState('rejected')->isFinal());
    }

    #[Test]
    public function test_has_submit_transition(): void
    {
        $definition = ApprovalWorkflow::build();
        $transition = $definition->getTransition('submit');

        self::assertSame(['draft'], $transition->froms);
        self::assertSame('pending_review', $transition->to);
        self::assertSame([], $transition->guards);
    }

    #[Test]
    public function test_approve_transition_requires_role_guard(): void
    {
        $definition = ApprovalWorkflow::build();
        $transition = $definition->getTransition('approve');

        self::assertSame(['pending_review'], $transition->froms);
        self::assertSame('approved', $transition->to);
        self::assertContains(RoleGuard::class, $transition->guards);
        self::assertSame(['reviewer'], $transition->metadata['required_roles']);
    }

    #[Test]
    public function test_reject_transition_requires_role_guard(): void
    {
        $definition = ApprovalWorkflow::build();
        $transition = $definition->getTransition('reject');

        self::assertSame(['pending_review'], $transition->froms);
        self::assertSame('rejected', $transition->to);
        self::assertContains(RoleGuard::class, $transition->guards);
    }

    #[Test]
    public function test_has_resubmit_transition(): void
    {
        $definition = ApprovalWorkflow::build();
        $transition = $definition->getTransition('resubmit');

        self::assertSame(['pending_review'], $transition->froms);
        self::assertSame('draft', $transition->to);
    }

    #[Test]
    public function test_custom_name(): void
    {
        $definition = ApprovalWorkflow::build(name: 'invoice_approval');

        self::assertSame('invoice_approval', $definition->name);
    }

    #[Test]
    public function test_custom_reviewer_roles(): void
    {
        $definition = ApprovalWorkflow::build(reviewerRoles: ['manager', 'director']);
        $transition = $definition->getTransition('approve');

        self::assertSame(['manager', 'director'], $transition->metadata['required_roles']);
    }

    #[Test]
    public function test_with_escalation_adds_escalated_state(): void
    {
        $definition = ApprovalWorkflow::build(withEscalation: true);

        self::assertTrue($definition->hasState('escalated'));
    }

    #[Test]
    public function test_with_escalation_adds_escalation_transitions(): void
    {
        $definition = ApprovalWorkflow::build(withEscalation: true);

        self::assertTrue($definition->hasTransition('escalate'));
        self::assertTrue($definition->hasTransition('escalation_approve'));
        self::assertTrue($definition->hasTransition('escalation_reject'));
    }

    #[Test]
    public function test_escalation_transitions_use_escalation_roles(): void
    {
        $definition = ApprovalWorkflow::build(
            withEscalation: true,
            escalationRoles: ['director'],
        );

        $transition = $definition->getTransition('escalation_approve');

        self::assertSame(['director'], $transition->metadata['required_roles']);
    }

    #[Test]
    public function test_without_escalation_has_no_escalated_state(): void
    {
        $definition = ApprovalWorkflow::build(withEscalation: false);

        self::assertFalse($definition->hasState('escalated'));
        self::assertFalse($definition->hasTransition('escalate'));
    }

    #[Test]
    public function test_metadata_marks_template_as_regulated(): void
    {
        $definition = ApprovalWorkflow::build();

        self::assertSame('approval', $definition->metadata['template']);
        self::assertTrue($definition->metadata['regulated']);
    }
}
