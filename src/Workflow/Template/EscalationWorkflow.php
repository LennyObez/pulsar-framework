<?php

declare(strict_types=1);

namespace Pulsar\Workflow\Template;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Workflow\Definition\DefinitionBuilder;
use Pulsar\Workflow\Definition\WorkflowDefinition;
use Pulsar\Workflow\Definition\WorkflowType;
use Pulsar\Workflow\Guard\RoleGuard;

use function count;
use function sprintf;

/**
 * Pre-built escalation workflow template with configurable escalation levels.
 *
 * Starts with an initial review, then auto-escalates through configurable
 * levels with different approver roles at each level. Each level can
 * approve or reject the item. Timeout-based escalation is handled
 * externally (e.g., via a scheduler that calls the escalation transition).
 *
 * States: submitted -> level_1 -> level_2 -> ... -> level_N -> approved | rejected
 */
#[Api(since: '1.0.0')]
final readonly class EscalationWorkflow
{
    /**
     * Build an escalation workflow with the specified number of levels.
     *
     * Each escalation level uses the corresponding entry from $rolesPerLevel.
     * If $rolesPerLevel has fewer entries than $levels, the last entry is reused.
     *
     * @param non-empty-string              $name          Definition name
     * @param int<1, 10>                    $levels        Number of escalation levels
     * @param list<list<string>>            $rolesPerLevel Approver roles for each level
     */
    #[NoDiscard]
    public static function build(
        string $name = 'escalation',
        int $levels = 3,
        array $rolesPerLevel = [['reviewer'], ['senior_reviewer'], ['manager']],
    ): WorkflowDefinition {
        $builder = DefinitionBuilder::create($name)
            ->type(WorkflowType::StateMachine)
            ->metadata(['template' => 'escalation', 'regulated' => true, 'levels' => $levels])
            ->initialState('submitted')
            ->finalState('approved')
            ->finalState('rejected');

        $previousState = 'submitted';

        for ($level = 1; $level <= $levels; $level++) {
            $stateName = sprintf('level_%d', $level);
            $fallbackIndex = count($rolesPerLevel) > 0 ? count($rolesPerLevel) - 1 : 0;
            $roles = $rolesPerLevel[$level - 1] ?? $rolesPerLevel[$fallbackIndex] ?? ['manager'];

            $builder->state($stateName, ['escalation_level' => $level]);

            $builder->transition(
                name: $level === 1 ? 'assign' : sprintf('escalate_to_%d', $level),
                from: $previousState,
                to: $stateName,
            );

            $builder->transition(
                name: sprintf('approve_level_%d', $level),
                from: $stateName,
                to: 'approved',
                guards: [RoleGuard::class],
                metadata: ['required_roles' => $roles],
            );

            $builder->transition(
                name: sprintf('reject_level_%d', $level),
                from: $stateName,
                to: 'rejected',
                guards: [RoleGuard::class],
                metadata: ['required_roles' => $roles],
            );

            $previousState = $stateName;
        }

        return $builder->build();
    }
}
