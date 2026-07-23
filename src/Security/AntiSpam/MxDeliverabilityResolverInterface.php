<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam;

use Pulsar\Api\Internal;

/**
 * Resolves whether an e-mail domain can receive mail.
 *
 * A domain is deliverable when it publishes an MX record, or — per RFC 5321
 * §5.1 (implicit MX) — an A/AAAA address record. The three-state return keeps
 * "the domain has no records" distinct from "the resolver could not be
 * reached", so the caller can fail open on the latter and never block every
 * sender during a DNS outage.
 */
#[Internal(reason: 'MX deliverability seam for EmailDomainCheck')]
interface MxDeliverabilityResolverInterface
{
    /**
     * @return bool|null true = deliverable (MX or A/AAAA present);
     *                   false = no deliverability records;
     *                   null = the resolver was unreachable (fail-open signal)
     */
    public function isDeliverable(string $domain): ?bool;
}
