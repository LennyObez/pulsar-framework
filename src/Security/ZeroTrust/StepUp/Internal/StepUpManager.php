<?php

declare(strict_types=1);

namespace Pulsar\Security\ZeroTrust\StepUp\Internal;

use DateTimeImmutable;
use Psr\EventDispatcher\EventDispatcherInterface;
use Pulsar\Api\Internal;
use Pulsar\Security\ZeroTrust\Event\StepUpAttemptedEvent;
use Pulsar\Security\ZeroTrust\Event\StepUpLockoutEvent;
use Pulsar\Security\ZeroTrust\StepUp\StepUpAction;
use Pulsar\Security\ZeroTrust\StepUp\StepUpConfig;
use Pulsar\Security\ZeroTrust\StepUp\StepUpState;

/**
 * Manages step-up authentication state per identity.
 *
 * Tracks attempt counts, enforces lockout thresholds, and emits
 * security events for monitoring and audit integration. State is
 * stored in-memory per request lifecycle; persistent storage is
 * the responsibility of the session layer.
 */
#[Internal(reason: 'Wired by composition root only')]
final class StepUpManager
{
    /** @var array<string, StepUpState> Keyed by "{identityId}:{ruleName}" */
    private array $states = [];

    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {}

    /**
     * Evaluate step-up state for an identity and rule, returning the action to take.
     *
     * If the identity is locked out, returns Deny.
     * If max attempts are not yet reached, records the attempt and returns Redirect.
     * If the attempt triggers lockout, records it and returns Deny.
     */
    public function handleStepUp(
        string $identityId,
        string $ruleName,
        StepUpConfig $config,
        DateTimeImmutable $now = new DateTimeImmutable(),
    ): StepUpAction {
        $key = $identityId . ':' . $ruleName;
        $state = $this->states[$key] ?? new StepUpState(identityId: $identityId, windowStart: $now);

        // If currently locked out, deny immediately
        if ($state->isLockedOut($now)) {
            return StepUpAction::Deny;
        }

        // If cooling down between attempts, deny
        if ($state->isCoolingDown($config, $now)) {
            return StepUpAction::Deny;
        }

        // Record the attempt
        $newState = $state->recordAttempt($config, $now);
        $this->states[$key] = $newState;

        $this->eventDispatcher->dispatch(new StepUpAttemptedEvent(
            identityId: $identityId,
            resource: $ruleName,
            attemptNumber: $newState->attemptCount,
            success: false,
        ));

        // If lockout was triggered by this attempt, emit lockout event
        $lockedUntil = $newState->lockedUntil;

        if ($newState->isLockedOut($now) && $lockedUntil !== null) {
            $this->eventDispatcher->dispatch(new StepUpLockoutEvent(
                identityId: $identityId,
                attemptCount: $newState->attemptCount,
                lockedUntil: $lockedUntil,
            ));

            return StepUpAction::Deny;
        }

        return StepUpAction::Redirect;
    }

    /**
     * Mark a step-up as successfully completed, resetting the state.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function markSuccess(string $identityId, string $ruleName): void
    {
        $key = $identityId . ':' . $ruleName;
        $state = $this->states[$key] ?? null;

        if ($state !== null) {
            $this->eventDispatcher->dispatch(new StepUpAttemptedEvent(
                identityId: $identityId,
                resource: $ruleName,
                attemptNumber: $state->attemptCount,
                success: true,
            ));

            $this->states[$key] = $state->reset();
        }
    }

    /**
     * Get the current state for an identity and rule (for inspection/testing).
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function getState(string $identityId, string $ruleName): ?StepUpState
    {
        return $this->states[$identityId . ':' . $ruleName] ?? null;
    }
}
