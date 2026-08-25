<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Evidence;

use Pulsar\Api\Api;

/**
 * Optional sub-contract for evidence stores that can persist the chain's
 * authenticated head alongside its records.
 *
 * {@see EvidenceChainStateAware} answers "is your medium fully readable". This
 * one answers the question that medium cannot: "how many records should be
 * there". They are separate because they fail separately — a register can be
 * perfectly readable and two records short — and because a store may be able to
 * answer one and not the other.
 *
 * Without a head, removal from the END of a chain is undetectable by
 * construction, not by oversight: every record's signature covers its
 * predecessor, so deleting the last N leaves a shorter chain in which every
 * surviving link still verifies against the link before it. Truncation is the
 * one tamper a hash chain cannot see, and an out-of-band commitment to the
 * height is the only thing that sees it. {@see EvidenceChainHead} is that
 * commitment; this contract is how a store keeps it.
 *
 * A store that does not implement this stays usable and forfeits the guarantee,
 * exactly as a store outside {@see EvidenceChainStateAware} forfeits the
 * unreadable-line guarantee. {@see \Pulsar\Compliance\Verification\EvidenceChain}
 * reports the forfeit as its own verdict rather than reporting such a register
 * intact — "no truncation was detected by a verifier that cannot detect
 * truncation" is the sentence this whole design exists to stop being printed.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
interface EvidenceChainHeadAware extends EvidenceStoreInterface
{
    /**
     * Whether an anchor has ever been written to this store.
     *
     * Distinct from {@see head()} returning null, which means an anchor is there
     * and could not be read. A chain that has never appended has no anchor and
     * that is not a finding; an anchor that was written and has since gone
     * missing is one, and collapsing the two into a single nullable would hand an
     * assessor the wrong sentence for a deleted anchor.
     *
     * Must never throw: an IO failure is itself a reason to refuse to append, and
     * the caller has no better answer for an exception than the one it already
     * has for a missing anchor.
     */
    public function hasHead(): bool;

    /**
     * The stored head, or null when there is none or it could not be decoded.
     *
     * Returns the head as written. Whether it authenticates is the chain's
     * question, not the store's — the store holds no key.
     *
     * Must never throw, for the reason {@see hasHead()} must not.
     */
    public function head(): ?EvidenceChainHead;

    /**
     * Replace the stored head.
     *
     * Called after every appended record, and it overwrites rather than appends:
     * the head is the chain's current height, not a log of heights.
     *
     * @throws EvidenceWriteFailedException when the anchor cannot be persisted. Loud
     *         on purpose — a register whose anchor silently stopped being maintained
     *         keeps looking verifiable while losing the one property the anchor
     *         provides.
     */
    public function writeHead(EvidenceChainHead $head): void;
}
