<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Workflow\Template;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Workflow\Definition\WorkflowType;
use Pulsar\Workflow\Guard\ExpressionGuard;
use Pulsar\Workflow\Guard\RoleGuard;
use Pulsar\Workflow\Template\AuditWorkflow;

#[CoversClass(AuditWorkflow::class)]
final class AuditWorkflowTest extends TestCase
{
    #[Test]
    public function test_build_produces_valid_definition(): void
    {
        $definition = AuditWorkflow::build();

        self::assertSame('audit', $definition->name);
        self::assertSame(WorkflowType::StateMachine, $definition->type);
    }

    #[Test]
    public function test_initial_state_is_draft(): void
    {
        $definition = AuditWorkflow::build();

        self::assertSame('draft', $definition->getInitialState()->name);
    }

    #[Test]
    public function test_has_correct_states(): void
    {
        $definition = AuditWorkflow::build();

        self::assertTrue($definition->hasState('draft'));
        self::assertTrue($definition->hasState('pending_change_review'));
        self::assertTrue($definition->hasState('change_approved'));
        self::assertTrue($definition->hasState('pending_signoff'));
        self::assertTrue($definition->hasState('signed_off'));
        self::assertTrue($definition->hasState('change_rejected'));
    }

    #[Test]
    public function test_signed_off_and_change_rejected_are_final(): void
    {
        $definition = AuditWorkflow::build();

        self::assertTrue($definition->getState('signed_off')->isFinal());
        self::assertTrue($definition->getState('change_rejected')->isFinal());
    }

    #[Test]
    public function test_sign_off_requires_auditor_role_and_review_comment(): void
    {
        $definition = AuditWorkflow::build();
        $transition = $definition->getTransition('sign_off');

        self::assertContains(RoleGuard::class, $transition->guards);
        self::assertContains(ExpressionGuard::class, $transition->guards);
        self::assertSame(['auditor'], $transition->metadata['required_roles']);
        self::assertSame('context:has:review_comment', $transition->metadata['guard_expression']);
    }

    #[Test]
    public function test_metadata_marks_mandatory_reason(): void
    {
        $definition = AuditWorkflow::build();

        self::assertTrue($definition->metadata['mandatory_reason']);
        self::assertSame('audit', $definition->metadata['template']);
    }

    #[Test]
    public function test_custom_roles(): void
    {
        $definition = AuditWorkflow::build(
            reviewerRoles: ['lead_reviewer'],
            auditorRoles: ['chief_auditor'],
        );

        $approveChange = $definition->getTransition('approve_change');
        self::assertSame(['lead_reviewer'], $approveChange->metadata['required_roles']);

        $signOff = $definition->getTransition('sign_off');
        self::assertSame(['chief_auditor'], $signOff->metadata['required_roles']);
    }
}
