<?php

declare(strict_types=1);

namespace Pulsar\Security\Audit;

use Pulsar\Api\Api;

/**
 * Result of verifying an audit log chain.
 *
 * Contains the overall verification outcome and details about any entries
 * that failed verification (HMAC mismatch or broken chain linkage).
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AuditChainResult
{
    /**
     * @param bool $valid Whether the entire chain is valid
     * @param int $verifiedCount Number of entries that passed verification
     * @param list<string> $failedEntryIds IDs of entries that failed verification
     * @param list<string> $brokenLinks IDs of entries with incorrect previousHmac chain linkage
     */
    public function __construct(
        public bool $valid,
        public int $verifiedCount,
        public array $failedEntryIds = [],
        public array $brokenLinks = [],
    ) {}
}
