<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Internal\Certificate\Revocation;

use Override;
use Pulsar\Api\Internal;

/**
 * Runs revocation checkers in order and returns the first definitive verdict.
 *
 * Wired as OCSP first, CRL second: OCSP is the timely, low-bandwidth mechanism,
 * and the CRL is the fallback for issuers that publish a distribution point but
 * no responder. A checker that returns {@see RevocationStatus::Unknown} (no
 * pointer, unreachable, unverified) hands off to the next; only a confirmed
 * Good/Revoked short-circuits. Unknown is returned only when every checker is
 * inconclusive.
 */
#[Internal(reason: 'Composes OCSP + CRL revocation for the PSD2 validator')]
final readonly class CompositeRevocationChecker implements RevocationCheckerInterface
{
    /** @var array<array-key, RevocationCheckerInterface> */
    private array $checkers;

    public function __construct(RevocationCheckerInterface ...$checkers)
    {
        $this->checkers = $checkers;
    }

    #[Override]
    public function check(string $leafPem, string $issuerPem): RevocationStatus
    {
        foreach ($this->checkers as $checker) {
            $status = $checker->check($leafPem, $issuerPem);

            if ($status !== RevocationStatus::Unknown) {
                return $status;
            }
        }

        return RevocationStatus::Unknown;
    }
}
