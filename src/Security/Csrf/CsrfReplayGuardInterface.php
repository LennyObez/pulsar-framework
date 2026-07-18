<?php

declare(strict_types=1);

namespace Pulsar\Security\Csrf;

use Pulsar\Api\Api;

/**
 * Single-use enforcement for self-contained CSRF tokens.
 *
 * A stateless token (see {@see EncryptedCsrfManager}) is valid for its whole
 * time window, so a token captured through a side channel — logs, Referer, a
 * shared proxy — can be replayed until it expires. Wiring a replay guard turns
 * such a token single-use: its embedded nonce is recorded on first acceptance
 * and refused thereafter, bounding a captured token to exactly one use.
 *
 * This trades the manager's "no server-side lookup" property for revocability;
 * it is therefore optional. The default session-backed manager already gets
 * single-use for free (the stored token is overwritten on rotation), so this
 * seam exists only for the opt-in stateless/encrypted managers.
 *
 * Implementations MUST make {@see consume()} atomic — the check and the mark
 * are one indivisible operation — or two concurrent replays of the same token
 * both pass. Back it with a store offering an atomic create/SETNX primitive
 * (the cache driver's `add()`); a plain has-then-set is a compromise, not an
 * implementation of this contract.
 * @api
 */
#[Api(since: '1.0.0')]
interface CsrfReplayGuardInterface
{
    /**
     * Atomically record a token's nonce as used.
     *
     * @param string $nonce       The token's unique nonce.
     * @param int    $ttlSeconds  How long to remember it — the token's own
     *                            window, after which it is expired anyway, so
     *                            remembering it no longer serves a purpose.
     *
     * @return bool true if the nonce was previously unseen (accept the token
     *              and it is now consumed); false if already seen (a replay) or
     *              if the guard could not record it (fail closed).
     */
    public function consume(string $nonce, int $ttlSeconds): bool;
}
