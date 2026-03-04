<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Social\Contracts;

use Pulsar\Api\Api;
use Pulsar\Extension\Auth\Social\Domain\OAuthRequest;
use Pulsar\Extension\Auth\Social\Domain\OAuthTokenSet;
use Pulsar\Extension\Auth\Social\Domain\SocialIdentity;

/**
 * Provider adapter contract for OAuth-based social authentication.
 *
 * Implementations wrap vendor-specific OAuth flows behind this uniform interface.
 */
#[Api(since: '1.0.0')]
interface OAuthProviderInterface
{
    /**
     * Get the provider name (e.g. "github", "google").
     */
    public function name(): string;

    /**
     * Build the authorization URL the user should be redirected to.
     */
    public function authorizationUrl(OAuthRequest $request): string;

    /**
     * Exchange an authorization code for an access/refresh token set.
     */
    public function exchangeCode(string $code, string $redirectUri, ?string $codeVerifier = null): OAuthTokenSet;

    /**
     * Map the token set to a normalized social identity.
     */
    public function mapIdentity(OAuthTokenSet $tokenSet): SocialIdentity;
}
