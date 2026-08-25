<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Verification;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Thrown when {@see EvidenceChain} is asked to append to a stored chain it
 * cannot verify under the key in service.
 *
 * The alternative to throwing is one of two forgeries. Appending anyway chains a
 * genuine record onto an unauthenticated one, so every later record verifies and
 * the break is buried mid-file where nothing looks at it again. Restarting from
 * genesis produces a short, perfectly valid chain whose validity is a statement
 * about nothing — the records it replaced are not mentioned by it. Both let a
 * deployment show an auditor a chain that verifies over a period in which the
 * evidence was, in fact, unverifiable.
 *
 * {@see ComplianceVerificationEngine::verify()} catches this at the recording
 * seam so a refused append never discards an already-built verification report,
 * and logs it as an error. The verification still runs; only the evidence
 * record is refused.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final class UnverifiableEvidenceChainException extends RuntimeException
{
    /**
     * @param string $reason What was found in the store, phrased for the operator
     *                       who has to decide what happened
     */
    #[NoDiscard]
    public static function refusingToAppend(string $reason): self
    {
        return new self(sprintf(
            'The compliance evidence chain cannot be resumed: %s Refusing to append until the '
                . 'register is archived and a new chain started, or the key that signed it is '
                . 'restored to service. Appending would make every later record verify across '
                . 'the break.',
            $reason,
        ));
    }
}
