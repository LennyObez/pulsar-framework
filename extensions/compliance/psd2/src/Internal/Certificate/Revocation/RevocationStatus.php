<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Internal\Certificate\Revocation;

use Pulsar\Api\Internal;

/**
 * The outcome of a certificate revocation check (OCSP or CRL).
 *
 * `Unknown` is distinct from `Good`/`Revoked`: it means the check could not
 * reach a definitive answer (no responder/CRL, unreachable, unparseable, or a
 * signature that did not verify). The caller decides whether Unknown is
 * tolerated (soft-fail) or rejected (hard-fail) by policy — it must never be
 * silently treated as Good.
 */
#[Internal(reason: 'Revocation check outcome')]
enum RevocationStatus
{
    case Good;
    case Revoked;
    case Unknown;
}
