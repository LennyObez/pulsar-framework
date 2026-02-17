<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\OAuth2\Contract;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Api;

/**
 * Top-level authorization server contract.
 *
 * Handles OAuth2 authorization and token endpoints per RFC 6749.
 * Implementations wrap a proven OAuth2 server library behind this port.
 */
#[Api(since: '1.0.0')]
interface AuthorizationServerInterface
{
    /**
     * Handle an authorization request (GET /authorize).
     *
     * Validates the client, redirect URI, scopes, and PKCE challenge.
     * Returns a response that either redirects to the client (with auth code)
     * or presents a consent screen.
     */
    public function handleAuthorizationRequest(ServerRequestInterface $request): ResponseInterface;

    /**
     * Handle a token request (POST /token).
     *
     * Processes grant types: authorization_code, client_credentials, refresh_token.
     * Returns a JSON response with access token, optional refresh token, and optional ID token.
     */
    public function handleTokenRequest(ServerRequestInterface $request): ResponseInterface;

    /**
     * Handle a token introspection request (POST /introspect).
     *
     * Returns token metadata per RFC 7662.
     */
    public function handleIntrospectionRequest(ServerRequestInterface $request): ResponseInterface;

    /**
     * Handle a token revocation request (POST /revoke).
     *
     * Revokes an access token or refresh token per RFC 7009.
     */
    public function handleRevocationRequest(ServerRequestInterface $request): ResponseInterface;
}
