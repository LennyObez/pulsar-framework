<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Workflow\Template;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Workflow\Definition\WorkflowType;
use Pulsar\Workflow\Guard\ExpressionGuard;
use Pulsar\Workflow\Guard\RoleGuard;
use Pulsar\Workflow\Template\ComplianceReviewWorkflow;

#[CoversClass(ComplianceReviewWorkflow::class)]
final class ComplianceReviewWorkflowTest extends TestCase
{
    #[Test]
    public function test_build_produces_valid_definition(): void
    {
        $definition = ComplianceReviewWorkflow::build();

        self::assertSame('compliance_review', $definition->name);
        self::assertSame(WorkflowType::StateMachine, $definition->type);
    }

    #[Test]
    public function test_initial_state_is_initial_assessment(): void
    {
        $definition = ComplianceReviewWorkflow::build();

        self::assertSame('initial_assessment', $definition->getInitialState()->name);
    }

    #[Test]
    public function test_has_correct_states(): void
    {
        $definition = ComplianceReviewWorkflow::build();

        self::assertTrue($definition->hasState('initial_assessment'));
        self::assertTrue($definition->hasState('remediation'));
        self::assertTrue($definition->hasState('verification'));
        self::assertTrue($definition->hasState('certification'));
        self::assertTrue($definition->hasState('certified'));
        self::assertTrue($definition->hasState('non_compliant'));
    }

    #[Test]
    public function test_certified_and_non_compliant_are_final(): void
    {
        $definition = ComplianceReviewWorkflow::build();

        self::assertTrue($definition->getState('certified')->isFinal());
        self::assertTrue($definition->getState('non_compliant')->isFinal());
    }

    #[Test]
    public function test_submit_assessment_requires_evidence(): void
    {
        $definition = ComplianceReviewWorkflow::build();
        $transition = $definition->getTransition('submit_assessment');

        self::assertContains(RoleGuard::class, $transition->guards);
        self::assertContains(ExpressionGuard::class, $transition->guards);
        self::assertSame('context:has:assessment_evidence', $transition->metadata['guard_expression']);
    }

    #[Test]
    public function test_submit_remediation_requires_evidence(): void
    {
        $definition = ComplianceReviewWorkflow::build();
        $transition = $definition->getTransition('submit_remediation');

        self::assertContains(ExpressionGuard::class, $transition->guards);
        self::assertSame('context:has:remediation_evidence', $transition->metadata['guard_expression']);
    }

    #[Test]
    public function test_verify_remediation_requires_evidence(): void
    {
        $definition = ComplianceReviewWorkflow::build();
        $transition = $definition->getTransition('verify_remediation');

        self::assertContains(ExpressionGuard::class, $transition->guards);
        self::assertSame('context:has:verification_evidence', $transition->metadata['guard_expression']);
    }

    #[Test]
    public function test_certify_requires_evidence(): void
    {
        $definition = ComplianceReviewWorkflow::build();
        $transition = $definition->getTransition('certify');

        self::assertContains(ExpressionGuard::class, $transition->guards);
        self::assertSame('context:has:certification_evidence', $transition->metadata['guard_expression']);
    }

    #[Test]
    public function test_fail_verification_loops_back_to_remediation(): void
    {
        $definition = ComplianceReviewWorkflow::build();
        $transition = $definition->getTransition('fail_verification');

        self::assertSame(['verification'], $transition->froms);
        self::assertSame('remediation', $transition->to);
    }

    #[Test]
    public function test_reject_certification_loops_back_to_verification(): void
    {
        $definition = ComplianceReviewWorkflow::build();
        $transition = $definition->getTransition('reject_certification');

        self::assertSame(['certification'], $transition->froms);
        self::assertSame('verification', $transition->to);
    }

    #[Test]
    public function test_custom_roles(): void
    {
        $definition = ComplianceReviewWorkflow::build(
            assessorRoles: ['lead_assessor'],
            remediatorRoles: ['engineer'],
            verifierRoles: ['qa_lead'],
            certifierRoles: ['compliance_officer'],
        );

        $submit = $definition->getTransition('submit_assessment');
        self::assertSame(['lead_assessor'], $submit->metadata['required_roles']);

        $remediate = $definition->getTransition('submit_remediation');
        self::assertSame(['engineer'], $remediate->metadata['required_roles']);

        $verify = $definition->getTransition('verify_remediation');
        self::assertSame(['qa_lead'], $verify->metadata['required_roles']);

        $certify = $definition->getTransition('certify');
        self::assertSame(['compliance_officer'], $certify->metadata['required_roles']);
    }

    #[Test]
    public function test_metadata_marks_mandatory_evidence(): void
    {
        $definition = ComplianceReviewWorkflow::build();

        self::assertTrue($definition->metadata['mandatory_evidence']);
        self::assertSame('compliance_review', $definition->metadata['template']);
        self::assertTrue($definition->metadata['regulated']);
    }
}
