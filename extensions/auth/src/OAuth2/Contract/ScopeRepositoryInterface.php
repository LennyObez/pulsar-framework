<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\OAuth2\Contract;

use Pulsar\Api\Api;
use Pulsar\Extension\Auth\OAuth2\Token\Scope;

/**
 * Repository for OAuth2 scope definitions and validation.
 * @api
 */
#[Api(since: '1.0.0')]
interface ScopeRepositoryInterface
{
    /**
     * Find a scope by its identifier.
     */
    public function findById(string $scopeId): ?Scope;

    /**
     * Validate and resolve a list of scope identifiers.
     *
     * Returns the resolved scopes, filtering out any invalid identifiers.
     * If the grant type restricts available scopes, only permitted scopes are returned.
     *
     * @param list<string> $scopeIds Requested scope identifiers
     * @param string $grantType The grant type being used
     * @param string $clientId The client requesting the scopes
     * @return list<Scope> Resolved valid scopes
     */
    public function resolveScopes(array $scopeIds, string $grantType, string $clientId): array;
}
