<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\OAuth2\Contract;

use Pulsar\Api\Api;
use Pulsar\Extension\Auth\OAuth2\Client\OAuthClient;
use Pulsar\Extension\Auth\OAuth2\Exception\OAuth2Exception;

/**
 * Repository for OAuth2 client registration and lookup.
 *
 * Handles both statically registered clients and (when enabled)
 * dynamically registered clients. Dynamic registration is disabled
 * by default and requires explicit admin policy configuration.
 */
#[Api(since: '1.0.0')]
interface ClientRepositoryInterface
{
    /**
     * Find a client by its identifier.
     */
    public function findById(string $clientId): ?OAuthClient;

    /**
     * Validate a client's credentials.
     *
     * For confidential clients, verifies the client secret.
     * For public clients, verifies the client exists and is active.
     *
     * @param string $grantType The grant type being used
     */
    public function validateClient(string $clientId, ?string $clientSecret, string $grantType): bool;

    /**
     * Register a new client.
     *
     * @throws OAuth2Exception If registration is disabled or client data is invalid
     */
    public function register(OAuthClient $client): void;

    /**
     * Revoke (deactivate) a client.
     */
    public function revoke(string $clientId): void;
}
