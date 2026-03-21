<?php

declare(strict_types=1);

namespace Pulsar\Security\Audit;

use Pulsar\Api\Api;

/**
 * Optional sub-contract for chainable sinks that can report whether
 * their backing store is empty, healthy, or corrupted.
 *
 * Audit sinks that implement this interface let `AuditLogger` fail
 * closed on corruption instead of silently re-seeding the chain
 * (F24.3). Sinks that don't implement it retain the historical
 * "seed on null" behaviour for backwards compatibility — but their
 * tamper-evidence guarantee is weaker, so production deployments
 * should prefer state-aware sinks (e.g. {@see AuditFileSink}).
 *
 * This is a separate interface from `ChainableAuditSinkInterface`
 * because adding `chainState()` to the existing interface would be a
 * breaking change for downstream sink implementations. New code can
 * implement both; legacy code keeps working unchanged.
 * @api
 */
#[Api(since: '1.0.0')]
interface AuditChainStateAware extends ChainableAuditSinkInterface
{
    /**
     * Inspect the backing store and report the discriminated chain
     * state. Must never throw — IO failures collapse to
     * {@see AuditChainState::Corrupted} so the logger refuses to
     * append to an unverifiable chain.
     */
    public function chainState(): AuditChainState;
}
