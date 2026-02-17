<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Social\Contracts;

use Pulsar\Api\Api;

/**
 * Manages cryptographic state tokens for OAuth CSRF protection.
 *
 * State tokens are single-use: verification consumes the token to prevent replay attacks.
 * Implementations must also store and retrieve PKCE code verifiers when PKCE is enabled.
 */
#[Api(since: '1.0.0')]
interface OAuthStateManagerInterface
{
    /**
     * Generate a new cryptographically random state token.
     */
    public function generate(): string;

    /**
     * Verify and consume a state token (one-time use).
     *
     * Returns true if the token was valid and not yet consumed; false otherwise.
     */
    public function verify(string $state): bool;

    /**
     * Store a PKCE code verifier associated with the given state token.
     */
    public function storePkceVerifier(string $state, string $verifier): void;

    /**
     * Retrieve and delete the PKCE code verifier for the given state token.
     *
     * Returns null if no verifier was stored for this state.
     */
    public function retrievePkceVerifier(string $state): ?string;
}
