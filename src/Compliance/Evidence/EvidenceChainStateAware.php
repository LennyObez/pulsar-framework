<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Evidence;

use Pulsar\Api\Api;
use Pulsar\Security\Audit\AuditChainState;

/**
 * Optional sub-contract for evidence stores that can report whether their
 * backing medium is empty, healthy, or corrupted.
 *
 * {@see EvidenceStoreInterface::all()} skips lines it cannot decode, on purpose:
 * one truncated line must not make the whole register unreadable. That is the
 * right answer for READING and the wrong one for RESUMING. A chain that resumes
 * from the last DECODABLE record after the real last record was truncated away
 * appends a successor whose linkage is internally consistent, and the truncation
 * disappears — the chain verifies, and the missing record is gone without a
 * trace. This contract is how a store says "there is something here I could not
 * read", so {@see \Pulsar\Compliance\Verification\EvidenceChain} can refuse to
 * append rather than silently paper over it.
 *
 * The state enum is {@see AuditChainState}, the audit log's, deliberately and
 * not by accident of naming: the compliance evidence chain and the audit chain
 * ask the same question of their store at resume time and must not answer it two
 * different ways. {@see \Pulsar\Security\Audit\AuditLogger} fails closed on
 * `Corrupted`; so does the evidence chain. A second enum with the same three
 * cases would be a second policy waiting to drift from the first.
 *
 * Separate from {@see EvidenceStoreInterface} because a store that cannot answer
 * (a database-backed one, a third-party one) must stay usable — it simply
 * forfeits the truncation guarantee, exactly as a sink that does not implement
 * {@see \Pulsar\Security\Audit\AuditChainStateAware} forfeits it for the audit
 * log.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
interface EvidenceChainStateAware extends EvidenceStoreInterface
{
    /**
     * Inspect the backing medium and report the discriminated chain state.
     *
     * Must never throw: an IO failure is itself a reason to refuse to append, so
     * it collapses to {@see AuditChainState::Corrupted} rather than escaping to
     * a caller that has no better answer for it.
     */
    public function chainState(): AuditChainState;
}
