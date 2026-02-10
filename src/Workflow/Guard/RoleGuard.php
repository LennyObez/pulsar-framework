<?php

declare(strict_types=1);

namespace Pulsar\Workflow\Guard;

use Pulsar\Api\Api;
use Pulsar\Workflow\ActorContext;
use Pulsar\Workflow\Definition\TransitionDefinition;
use Pulsar\Workflow\Storage\WorkflowInstance;

use function array_intersect;
use function implode;
use function sprintf;

/**
 * Guard that checks whether the actor holds any of the required roles.
 *
 * Roles are read from the transition metadata under the 'required_roles' key
 * and matched against the actor's claims snapshot. The actor's roles are
 * expected in the workflow instance context under the 'actor_roles' key.
 */
#[Api(since: '1.0.0')]
final readonly class RoleGuard implements TransitionGuardInterface
{
    /**
     * Metadata key on the transition that contains the required role list.
     */
    private const string REQUIRED_ROLES_KEY = 'required_roles';

    /**
     * Context key on the workflow instance that contains the actor's roles.
     */
    private const string ACTOR_ROLES_KEY = 'actor_roles';

    public function evaluate(
        ActorContext $actor,
        TransitionDefinition $transition,
        WorkflowInstance $instance,
    ): GuardResult {
        /** @var list<string> $requiredRoles */
        $requiredRoles = $transition->metadata[self::REQUIRED_ROLES_KEY] ?? [];

        if ($requiredRoles === []) {
            return GuardResult::allow();
        }

        /** @var list<string> $actorRoles */
        $actorRoles = $instance->context->has(self::ACTOR_ROLES_KEY)
            ? (array) $instance->context->get(self::ACTOR_ROLES_KEY)
            : [];

        $matched = array_intersect($requiredRoles, $actorRoles);

        if ($matched !== []) {
            return GuardResult::allow();
        }

        return GuardResult::deny(sprintf(
            'Actor "%s" does not hold any of the required roles: %s',
            $actor->subjectId,
            implode(', ', $requiredRoles),
        ));
    }
}
