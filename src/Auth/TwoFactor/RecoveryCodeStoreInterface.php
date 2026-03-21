<?php

declare(strict_types=1);

namespace Pulsar\Auth\TwoFactor;

use Pulsar\Api\Api;

/**
 * Persistence contract for recovery code sets.
 *
 * Implementations provide atomic consume to prevent race conditions
 * where two concurrent requests could both consume the same code.
 * @api
 */
#[Api(since: '1.0.0')]
interface RecoveryCodeStoreInterface
{
    /**
     * Load the recovery code set for an identity.
     *
     * @return RecoveryCodeSet|null null when the identity has no recovery codes
     */
    public function loadSet(string $identityId): ?RecoveryCodeSet;

    /**
     * Atomically consume a recovery code by its hash.
     *
     * Must be atomic: if two concurrent requests try to consume the same code,
     * exactly one succeeds and the other returns AlreadyUsed.
     *
     * @param string $identityId Identity owning the code set
     * @param string $codeHash HMAC hash of the code to consume
     */
    public function consume(string $identityId, string $codeHash): ConsumeResult;

    /**
     * Store a recovery code set for an identity.
     *
     * Overwrites any existing set.
     */
    public function store(string $identityId, RecoveryCodeSet $set): void;
}
