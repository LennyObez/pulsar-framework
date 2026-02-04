<?php

declare(strict_types=1);

namespace Pulsar\Security\Audit;

use Pulsar\Api\Api;

/**
 * Audit sink that can report the last written HMAC for chain continuity.
 *
 * Extends AuditSinkInterface with the ability to read back the last HMAC
 * from the backing store, allowing the AuditLogger to resume the chain
 * across process restarts instead of always re-seeding.
 */
#[Api(since: '1.0.0')]
interface ChainableAuditSinkInterface extends AuditSinkInterface
{
    /**
     * Read the HMAC of the last written audit entry.
     *
     * Returns null if the store is empty, the file is missing, or the
     * last entry cannot be parsed. Must never throw — callers fall back
     * to the seed HMAC on null.
     */
    public function lastHmac(): ?string;
}
