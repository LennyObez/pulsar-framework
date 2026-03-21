<?php

declare(strict_types=1);

namespace Pulsar\Workflow\Exception;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Base exception for workflow and saga errors.
 *
 * Uses static factory methods per Pulsar convention to produce
 * domain-specific error messages with structured context.
 * @api
 */
#[Api(since: '1.0.0')]
class WorkflowException extends RuntimeException
{
    /**
     * A transition was attempted that is not defined or not allowed from the current state.
     */
    #[NoDiscard]
    public static function invalidTransition(string $transitionName, string $fromState, string $definitionName): self
    {
        return new self(sprintf(
            'Transition "%s" is not valid from state "%s" in workflow "%s"',
            $transitionName,
            $fromState,
            $definitionName,
        ));
    }

    /**
     * A referenced state does not exist in the workflow definition.
     */
    #[NoDiscard]
    public static function invalidState(string $stateName, string $definitionName): self
    {
        return new self(sprintf(
            'State "%s" does not exist in workflow definition "%s"',
            $stateName,
            $definitionName,
        ));
    }

    /**
     * A workflow definition could not be found by name.
     */
    #[NoDiscard]
    public static function definitionNotFound(string $definitionName): self
    {
        return new self(sprintf(
            'Workflow definition "%s" not found',
            $definitionName,
        ));
    }

    /**
     * A specific version of a definition could not be found.
     */
    #[NoDiscard]
    public static function versionNotFound(string $definitionId, int $version): self
    {
        return new self(sprintf(
            'Version %d of workflow definition "%s" not found',
            $version,
            $definitionId,
        ));
    }

    /**
     * A workflow definition is structurally invalid (e.g., missing initial state, orphan states).
     */
    #[NoDiscard]
    public static function invalidDefinition(string $definitionName, string $reason): self
    {
        return new self(sprintf(
            'Workflow definition "%s" is invalid: %s',
            $definitionName,
            $reason,
        ));
    }

    /**
     * A guard blocked a transition.
     */
    #[NoDiscard]
    public static function guardBlocked(string $transitionName, string $guardClass, string $reason): self
    {
        return new self(sprintf(
            'Transition "%s" blocked by guard "%s": %s',
            $transitionName,
            $guardClass,
            $reason,
        ));
    }

    /**
     * Concurrent modification detected via optimistic locking.
     */
    #[NoDiscard]
    public static function concurrentTransition(string $instanceId, int $expectedVersion, int $actualVersion): self
    {
        return new self(sprintf(
            'Concurrent transition conflict on instance "%s": expected version %d, actual version %d',
            $instanceId,
            $expectedVersion,
            $actualVersion,
        ));
    }

    /**
     * A transition targets a final state that cannot be left.
     */
    #[NoDiscard]
    public static function transitionFromFinalState(string $stateName, string $definitionName): self
    {
        return new self(sprintf(
            'Cannot transition from final state "%s" in workflow "%s"',
            $stateName,
            $definitionName,
        ));
    }

    /**
     * An ActorContext was constructed with an empty subjectId.
     */
    #[NoDiscard]
    public static function emptyActorSubject(): self
    {
        return new self('ActorContext subjectId must not be empty');
    }

    /**
     * A classified context value violates the field contract
     * (wrong type, out-of-range tag, missing required attribute).
     */
    #[NoDiscard]
    public static function invalidClassifiedField(string $field, string $reason): self
    {
        return new self(sprintf(
            'Invalid classified-context field "%s": %s',
            $field,
            $reason,
        ));
    }
}
