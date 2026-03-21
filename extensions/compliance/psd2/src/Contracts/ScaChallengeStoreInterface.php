<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Contracts;

use Pulsar\Api\Api;
use Pulsar\Extension\Psd2\Domain\ScaChallenge;

/**
 * Persistence for SCA challenges.
 *
 * Challenges are short-lived and must be stored atomically
 * to prevent race conditions during verification.
 * @api
 */
#[Api(since: '1.0.0')]
interface ScaChallengeStoreInterface
{
    /**
     * Store a challenge.
     */
    public function store(ScaChallenge $challenge): void;

    /**
     * Retrieve a challenge by its ID. Returns null if not found or expired.
     */
    public function find(string $challengeId): ?ScaChallenge;

    /**
     * Remove a challenge after successful verification or expiry.
     */
    public function remove(string $challengeId): void;
}
