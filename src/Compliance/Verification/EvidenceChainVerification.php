<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Verification;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * What {@see EvidenceChain::verify()} found.
 *
 * Replaces the `array{valid: bool, verified: int, broken_at: list<string>}` the
 * chain used to return. That shape had two problems and both were load-bearing:
 * `valid` was true for a truncated register, because the survivors of a
 * truncation still chain to each other; and a caller had to hand IN the records,
 * so a register the store knew it could not fully read was verified from
 * whatever happened to decode.
 *
 * Every field here is a count the verifier established by reading the store
 * itself, so a report can state what was checked rather than that something was.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class EvidenceChainVerification
{
    /**
     * @param int           $present  Records found in the register that this chain wrote
     * @param int|null      $attested Height the anchor commits to, or null when the store
     *                                cannot attest one — the difference between the two is
     *                                what a truncation looks like
     * @param int           $verified Records whose signature and linkage were recomputed and held
     * @param int           $examined Records whose signature was recomputed at all: equal to
     *                                $present for an unbounded run, smaller when the caller
     *                                bounded the work. "Verified 500 of 40,000" is a materially
     *                                different claim from "verified", so both numbers travel
     * @param list<string>  $brokenAt What the finding points at, oldest first: record ids for
     *                                {@see EvidenceChainVerdict::Modified}, and chain positions
     *                                for a register missing records or holding them out of
     *                                order — a record that is not there has no id to name
     * @param string        $summary  One sentence naming what was found, for the operator
     *                                who has to act on it and for the assessor who reads it
     */
    public function __construct(
        public EvidenceChainVerdict $verdict,
        public int $present,
        public ?int $attested,
        public int $verified,
        public int $examined,
        public array $brokenAt,
        public string $summary,
    ) {}

    /**
     * Whether this register may be presented as evidence.
     */
    #[NoDiscard]
    public function admissible(): bool
    {
        return $this->verdict->admissible();
    }
}
