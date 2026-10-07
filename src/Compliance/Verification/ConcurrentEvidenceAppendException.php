<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Verification;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Thrown when a second call reaches {@see EvidenceChain::record()} while one is
 * still inside the append.
 *
 * The append resumes the chain, signs a record carrying the chain's current
 * height as its position, stores it, publishes the new signature and re-anchors.
 * A call that enters that window reads the same height and the same predecessor
 * signature, so both records claim position N. {@see EvidenceChain::verify()}
 * reports a position that appears twice as {@see EvidenceChainVerdict::Reordered}
 * — correctly, but after the fact, and about a register that can no longer be
 * repaired.
 *
 * WHY IT REFUSES INSTEAD OF WAITING. The realistic second entrant is a nested
 * call on the same stack — an evidence store, or an observer it reaches, that
 * records evidence of its own — and the first call cannot proceed until the
 * second returns, so no amount of waiting clears it. A genuinely interleaved
 * second entrant cannot be waited out either: this class does not know which
 * scheduler, if any, is driving the call that holds the append, so it cannot know
 * what would wake it. Under the execution model of ADR-0071 there is no sanctioned
 * way for two executions to be inside this method at once, so the second one is a
 * defect, and a defect on a tamper-evidence mechanism is reported rather than
 * absorbed.
 *
 * {@see ComplianceVerificationEngine::verify()} catches everything at the
 * recording seam, so a refused append never discards an already-built
 * verification report; it is logged as an error there.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final class ConcurrentEvidenceAppendException extends RuntimeException
{
    /**
     * @param int $position The chain height both appends would have claimed
     */
    #[NoDiscard]
    public static function refusingToInterleave(int $position): self
    {
        return new self(sprintf(
            'The compliance evidence chain is already appending at position %d. A second record '
                . 'would claim the same position and the same predecessor signature, which '
                . 'verification reports as a reordered register. Refusing the second append: an '
                . 'evidence store or observer is recording evidence of its own from inside the '
                . 'append it was called by.',
            $position,
        ));
    }
}
