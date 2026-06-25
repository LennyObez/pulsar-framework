<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\ManagedChallenge;

use Pulsar\Api\Internal;

/**
 * An issued managed-challenge puzzle.
 *
 * The client must find a solution string such that
 * `SHA-256($id . '.' . $solution)` has at least {@see $bits} leading zero
 * bits. The difficulty and issue time are signed into the challenge token so
 * the client cannot weaken the puzzle or extend its lifetime.
 */
#[Internal(reason: 'Managed challenge value object; produced/consumed by ManagedChallengeService')]
final readonly class ManagedChallenge
{
    /**
     * @param string $id        Random challenge identifier (hex).
     * @param int    $bits       Required leading zero bits of the SHA-256 solution.
     * @param int    $issuedAt   Unix timestamp the challenge was minted.
     */
    public function __construct(
        public string $id,
        public int $bits,
        public int $issuedAt,
    ) {}
}
