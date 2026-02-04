<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Contracts;

use Pulsar\Api\Api;

/**
 * Manages cryptographic state tokens for OAuth CSRF protection.
 *
 * State tokens are single-use: verification consumes the token to prevent replay attacks.
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
}
