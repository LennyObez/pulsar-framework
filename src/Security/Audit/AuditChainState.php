<?php

declare(strict_types=1);

namespace Pulsar\Security\Audit;

use Pulsar\Api\Api;

/**
 * Discriminated state of an audit chain at logger startup.
 *
 * `ChainableAuditSinkInterface::lastHmac()` returns a nullable string
 * and must never throw, so a non-empty store whose last entry is
 * corrupt collapses into the same `null` shape that means "fresh
 * chain, please seed". A verifier reading that would accept the
 * post-corruption seed as a legitimate fresh chain, forfeiting the
 * tamper-evidence guarantee ADR-0008 makes.
 *
 * This enum lets sinks distinguish the three meaningful cases so the
 * logger can fail closed on `Corrupted` instead of re-seeding over
 * corrupted state. The contract is consumed via
 * `AuditChainStateAware::chainState()`; sinks that do not implement it
 * keep the nullable-only semantics for backwards compatibility and
 * lose the tamper-evidence guarantee.
 * @api
 */
#[Api(since: '1.0.0')]
enum AuditChainState
{
    /**
     * Sink is empty (no prior entries). The logger must seed the chain
     * from `SEED_MESSAGE` — the same signal as `lastHmac() === null`.
     */
    case Empty;

    /**
     * Sink holds at least one entry and the last entry parsed cleanly.
     * The logger resumes from `lastHmac()`.
     */
    case Healthy;

    /**
     * Sink holds at least one entry but the last entry could not be
     * read or parsed (truncated file, malformed JSON, missing `hmac`
     * field, IO exception). Continuing would either re-seed and forge
     * a "fresh" chain post-corruption or chain to an unknown HMAC: both
     * break tamper-evidence. The logger MUST fail closed in this state.
     */
    case Corrupted;
}
