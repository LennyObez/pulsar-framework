<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\WebAuthn\Contract;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Server-side record of which challenges have already been answered.
 *
 * `challenge_ttl_seconds` bounds how long a captured ceremony response stays
 * usable; it does not stop the response being used twice inside that window.
 * Bounding is not closing: a second factor whose assertion can be replayed for
 * up to five minutes is a second factor an attacker who sees one successful
 * ceremony can repeat. This port is what closes it, and it is the reason
 * {@see WebAuthnServerInterface} may say "one-time challenges" at all.
 *
 * The contract is a CLAIM, not a lookup. `consume()` both tests and marks, and
 * the two must happen as one indivisible step: a `has()` followed by a
 * `markSpent()` written by the caller loses to two concurrent replays that
 * interleave between the two calls, which is exactly the race an attacker firing
 * the same captured assertion at several workers is trying to win. An
 * implementation that cannot make the claim atomic against its backing store
 * must not implement this interface.
 *
 * Implementations must also be shared across everything that verifies a
 * ceremony. A per-request or per-instance store answers "not spent" every time
 * and silently reopens the window; a deployment running more than one worker
 * process therefore needs a shared backend (Redis `SET NX`, a unique index on a
 * database column, a memcached `add`), not the in-memory default
 * {@see \Pulsar\Extension\Auth\WebAuthn\Adapter\InMemoryChallengeStore}.
 *
 * The store never needs to outlive the challenge: a value past its TTL is
 * already refused by
 * {@see \Pulsar\Extension\Auth\WebAuthn\Ceremony\Challenge::isFresh()} before
 * consumption is attempted, so `$expiresAt` is handed over precisely so an
 * implementation can expire its own record and stay bounded in size.
 * @api
 */
#[Api(since: '1.0.0')]
interface ChallengeStoreInterface
{
    /**
     * Atomically claim a challenge as spent.
     *
     * @param string            $challenge The base64url challenge string the relying party issued
     * @param DateTimeImmutable $expiresAt The instant past which the challenge is refused on
     *                                     freshness alone, so the record may be dropped
     *
     * @return bool `true` when this call is the one that claimed the challenge,
     *              `false` when it had already been claimed. A `false` return is
     *              a replay and the ceremony must be refused.
     */
    public function consume(string $challenge, DateTimeImmutable $expiresAt): bool;
}
