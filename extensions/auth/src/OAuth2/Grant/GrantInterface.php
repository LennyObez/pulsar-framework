<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\OAuth2\Grant;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Api;
use Pulsar\Extension\Auth\OAuth2\Client\OAuthClient;
use Pulsar\Extension\Auth\OAuth2\Exception\OAuth2Exception;

/**
 * Contract for an OAuth2 grant type handler.
 *
 * Each grant type (authorization_code, client_credentials, refresh_token)
 * implements this interface to handle token requests for that grant.
 * @api
 */
#[Api(since: '1.0.0')]
interface GrantInterface
{
    /**
     * The grant_type identifier (e.g., 'authorization_code', 'client_credentials').
     */
    public function identifier(): string;

    /**
     * Handle a token request for this grant type.
     *
     * @throws OAuth2Exception On protocol errors
     */
    public function handleTokenRequest(ServerRequestInterface $request, OAuthClient $client): TokenResponse;
}
