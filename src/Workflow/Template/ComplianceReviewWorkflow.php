<?php

declare(strict_types=1);

namespace Pulsar\Workflow\Template;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Workflow\Definition\DefinitionBuilder;
use Pulsar\Workflow\Definition\WorkflowDefinition;
use Pulsar\Workflow\Definition\WorkflowType;
use Pulsar\Workflow\Guard\ExpressionGuard;
use Pulsar\Workflow\Guard\RoleGuard;

/**
 * Pre-built compliance review workflow: Assess -> Remediate -> Verify -> Certify.
 *
 * Each stage requires mandatory evidence attachment (enforced via
 * ExpressionGuard checking for evidence context fields) and appropriate
 * role authorization. Failed assessments can loop back to remediation.
 *
 * States: initial_assessment -> remediation -> verification -> certification -> certified | non_compliant
 */
#[Api(since: '1.0.0')]
final readonly class ComplianceReviewWorkflow
{
    /**
     * Build a compliance review workflow definition.
     *
     * @param non-empty-string $name            Definition name
     * @param list<string>     $assessorRoles   Roles allowed to perform assessments
     * @param list<string>     $remediatorRoles Roles allowed to submit remediation
     * @param list<string>     $verifierRoles   Roles allowed to verify remediation
     * @param list<string>     $certifierRoles  Roles allowed to certify compliance
     */
    #[NoDiscard]
    public static function build(
        string $name = 'compliance_review',
        array $assessorRoles = ['assessor'],
        array $remediatorRoles = ['remediator'],
        array $verifierRoles = ['verifier'],
        array $certifierRoles = ['certifier'],
    ): WorkflowDefinition {
        return DefinitionBuilder::create($name)
            ->type(WorkflowType::StateMachine)
            ->metadata([
                'template' => 'compliance_review',
                'regulated' => true,
                'mandatory_evidence' => true,
            ])
            ->initialState('initial_assessment')
            ->state('remediation')
            ->state('verification')
            ->state('certification')
            ->finalState('certified')
            ->finalState('non_compliant')
            ->transition(
                name: 'submit_assessment',
                from: 'initial_assessment',
                to: 'remediation',
                guards: [RoleGuard::class, ExpressionGuard::class],
                metadata: [
                    'required_roles' => $assessorRoles,
                    'guard_expression' => 'context:has:assessment_evidence',
                ],
            )
            ->transition(
                name: 'declare_non_compliant',
                from: 'initial_assessment',
                to: 'non_compliant',
                guards: [RoleGuard::class],
                metadata: ['required_roles' => $assessorRoles],
            )
            ->transition(
                name: 'submit_remediation',
                from: 'remediation',
                to: 'verification',
                guards: [RoleGuard::class, ExpressionGuard::class],
                metadata: [
                    'required_roles' => $remediatorRoles,
                    'guard_expression' => 'context:has:remediation_evidence',
                ],
            )
            ->transition(
                name: 'verify_remediation',
                from: 'verification',
                to: 'certification',
                guards: [RoleGuard::class, ExpressionGuard::class],
                metadata: [
                    'required_roles' => $verifierRoles,
                    'guard_expression' => 'context:has:verification_evidence',
                ],
            )
            ->transition(
                name: 'fail_verification',
                from: 'verification',
                to: 'remediation',
                guards: [RoleGuard::class],
                metadata: ['required_roles' => $verifierRoles],
            )
            ->transition(
                name: 'certify',
                from: 'certification',
                to: 'certified',
                guards: [RoleGuard::class, ExpressionGuard::class],
                metadata: [
                    'required_roles' => $certifierRoles,
                    'guard_expression' => 'context:has:certification_evidence',
                ],
            )
            ->transition(
                name: 'reject_certification',
                from: 'certification',
                to: 'verification',
                guards: [RoleGuard::class],
                metadata: ['required_roles' => $certifierRoles],
            )
            ->build();
    }
}
