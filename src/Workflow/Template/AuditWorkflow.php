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
 * Pre-built audit workflow template: Change -> Review -> Sign-off.
 *
 * Designed for regulated environments where every transition requires
 * a mandatory reason and all transitions are logged to the audit trail.
 * The sign-off step requires an auditor role and ensures that a review
 * comment exists in the context before sign-off is allowed.
 *
 * States: draft -> pending_change_review -> change_approved -> pending_signoff -> signed_off | change_rejected
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AuditWorkflow
{
    /**
     * Build a standard audit workflow definition.
     *
     * @param non-empty-string $name          Definition name
     * @param list<string>     $reviewerRoles Roles allowed to approve/reject changes
     * @param list<string>     $auditorRoles  Roles allowed to sign off
     */
    #[NoDiscard]
    public static function build(
        string $name = 'audit',
        array $reviewerRoles = ['reviewer'],
        array $auditorRoles = ['auditor'],
    ): WorkflowDefinition {
        return DefinitionBuilder::create($name)
            ->type(WorkflowType::StateMachine)
            ->metadata([
                'template' => 'audit',
                'regulated' => true,
                'mandatory_reason' => true,
            ])
            ->initialState('draft')
            ->state('pending_change_review')
            ->state('change_approved')
            ->state('pending_signoff')
            ->finalState('signed_off')
            ->finalState('change_rejected')
            ->transition(
                name: 'submit_change',
                from: 'draft',
                to: 'pending_change_review',
            )
            ->transition(
                name: 'approve_change',
                from: 'pending_change_review',
                to: 'change_approved',
                guards: [RoleGuard::class],
                metadata: ['required_roles' => $reviewerRoles],
            )
            ->transition(
                name: 'reject_change',
                from: 'pending_change_review',
                to: 'change_rejected',
                guards: [RoleGuard::class],
                metadata: ['required_roles' => $reviewerRoles],
            )
            ->transition(
                name: 'request_signoff',
                from: 'change_approved',
                to: 'pending_signoff',
            )
            ->transition(
                name: 'sign_off',
                from: 'pending_signoff',
                to: 'signed_off',
                guards: [RoleGuard::class, ExpressionGuard::class],
                metadata: [
                    'required_roles' => $auditorRoles,
                    'guard_expression' => 'context:has:review_comment',
                ],
            )
            ->transition(
                name: 'return_for_review',
                from: 'pending_signoff',
                to: 'pending_change_review',
                guards: [RoleGuard::class],
                metadata: ['required_roles' => $auditorRoles],
            )
            ->build();
    }
}
