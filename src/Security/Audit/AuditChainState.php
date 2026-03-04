<?php

declare(strict_types=1);

namespace Pulsar\Security\Audit;

use Pulsar\Api\Api;

/**
 * Discriminated state of an audit chain at logger startup.
 *
 * `ChainableAuditSinkInterface::lastHmac()` historically returned a
 * nullable string and the contract said "must never throw" — every
 * non-empty file with a corrupt last entry collapsed into the same
 * `null` shape that signals "fresh chain, please seed". A naïve
 * verifier would then accept the post-corruption seed as a legitimate
 * fresh chain (F24.3, ADR-0008 promise broken).
 *
 * This enum lets sinks distinguish the three meaningful cases so the
 * logger can fail closed on `Corrupted` instead of silently re-seeding
 * over corrupted state. The contract is consumed via
 * `AuditChainStateAware::chainState()`; sinks that do not implement it
 * keep the legacy semantics for backwards compatibility but lose the
 * tamper-evidence guarantee.
 */
#[Api(since: '1.0.0')]
enum AuditChainState
{
    /**
     * Sink is empty (no prior entries). The logger must seed the chain
     * from `SEED_MESSAGE` — same as `lastHmac() === null` in the
     * legacy contract.
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
