<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Social\Contracts;

use Pulsar\Api\Api;
use Pulsar\Extension\Auth\Social\Domain\SsoLoginResult;
use Pulsar\Extension\Auth\Social\Exception\SsoException;
use Pulsar\Extension\Auth\Social\Features\InitiateLogin\InitiateLoginResult;

/**
 * Top-level gateway for social SSO login flows.
 *
 * Orchestrates both legs of the OAuth flow: the initiate step (build the
 * provider authorization URL to redirect to) and the full callback (state
 * verification, code exchange, identity mapping, and account linking).
 * @api
 */
#[Api(since: '1.0.0')]
interface SsoGatewayInterface
{
    /**
     * Begin a social login: build the provider authorization URL (plus CSRF
     * state and the optional PKCE challenge) the user should be redirected to.
     *
     * @throws SsoException on any SSO failure
     */
    public function initiate(string $providerName, ?string $redirectUri = null): InitiateLoginResult;

    /**
     * Execute the full social login flow for an OAuth callback.
     *
     * @throws SsoException on any SSO failure
     */
    public function fullLogin(string $providerName, string $code, string $state): SsoLoginResult;
}
