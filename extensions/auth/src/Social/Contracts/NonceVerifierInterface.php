<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Social\Contracts;

use Pulsar\Api\Api;
use Pulsar\Extension\Auth\Social\Domain\IdTokenClaims;

/**
 * Manages nonce generation and verification for OpenID Connect flows.
 *
 * Nonces prevent ID token replay attacks by binding tokens to specific authentication requests.
 * @api
 */
#[Api(since: '1.0.0')]
interface NonceVerifierInterface
{
    /**
     * Generate a new cryptographically random nonce value.
     */
    public function generate(): string;

    /**
     * Verify that the nonce in the verified claims matches an outstanding request.
     */
    public function verify(string $nonce, IdTokenClaims $verifiedClaims): bool;
}
