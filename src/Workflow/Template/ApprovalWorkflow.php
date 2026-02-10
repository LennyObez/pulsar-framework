<?php

declare(strict_types=1);

namespace Pulsar\Workflow\Template;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Workflow\Definition\DefinitionBuilder;
use Pulsar\Workflow\Definition\WorkflowDefinition;
use Pulsar\Workflow\Definition\WorkflowType;
use Pulsar\Workflow\Guard\RoleGuard;

/**
 * Pre-built approval workflow template: Submit -> Review -> Approve/Reject.
 *
 * Guards ensure only actors with the configured reviewer role can approve
 * or reject. The workflow supports optional timeout escalation by including
 * an escalation state that can be reached from the review state.
 *
 * Customize role names, state names, and metadata via the factory parameters.
 */
#[Api(since: '1.0.0')]
final readonly class ApprovalWorkflow
{
    /**
     * Build a standard approval workflow definition.
     *
     * States: draft -> pending_review -> approved | rejected
     * Optional: pending_review -> escalated -> approved | rejected
     *
     * @param non-empty-string $name            Definition name
     * @param list<string>     $reviewerRoles   Roles allowed to approve/reject
     * @param bool             $withEscalation  Include an escalation state
     * @param list<string>     $escalationRoles Roles allowed to approve/reject after escalation
     */
    #[NoDiscard]
    public static function build(
        string $name = 'approval',
        array $reviewerRoles = ['reviewer'],
        bool $withEscalation = false,
        array $escalationRoles = ['senior_reviewer', 'manager'],
    ): WorkflowDefinition {
        $builder = DefinitionBuilder::create($name)
            ->type(WorkflowType::StateMachine)
            ->metadata(['template' => 'approval', 'regulated' => true])
            ->initialState('draft')
            ->state('pending_review')
            ->finalState('approved')
            ->finalState('rejected')
            ->transition(
                name: 'submit',
                from: 'draft',
                to: 'pending_review',
            )
            ->transition(
                name: 'approve',
                from: 'pending_review',
                to: 'approved',
                guards: [RoleGuard::class],
                metadata: ['required_roles' => $reviewerRoles],
            )
            ->transition(
                name: 'reject',
                from: 'pending_review',
                to: 'rejected',
                guards: [RoleGuard::class],
                metadata: ['required_roles' => $reviewerRoles],
            )
            ->transition(
                name: 'resubmit',
                from: 'pending_review',
                to: 'draft',
            );

        if ($withEscalation) {
            $builder
                ->state('escalated', ['escalation_level' => 1])
                ->transition(
                    name: 'escalate',
                    from: 'pending_review',
                    to: 'escalated',
                )
                ->transition(
                    name: 'escalation_approve',
                    from: 'escalated',
                    to: 'approved',
                    guards: [RoleGuard::class],
                    metadata: ['required_roles' => $escalationRoles],
                )
                ->transition(
                    name: 'escalation_reject',
                    from: 'escalated',
                    to: 'rejected',
                    guards: [RoleGuard::class],
                    metadata: ['required_roles' => $escalationRoles],
                );
        }

        return $builder->build();
    }
}
