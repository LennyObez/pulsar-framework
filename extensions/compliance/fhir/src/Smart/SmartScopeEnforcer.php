<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Smart;

use Pulsar\Api\Api;

/**
 * Enforces SMART on FHIR scope-based access control.
 *
 * Given a set of granted scopes (from OAuth2 token), determines whether
 * a specific FHIR operation is allowed.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SmartScopeEnforcer
{
    /**
     * Check whether the granted scopes allow a specific resource access.
     *
     * @param list<SmartScope> $grantedScopes Scopes from the access token
     * @param string           $resourceType  FHIR resource type
     * @param string           $permission    "read" or "write"
     */
    public function isAllowed(array $grantedScopes, string $resourceType, string $permission): bool
    {
        foreach ($grantedScopes as $scope) {
            if ($scope->grants($resourceType, $permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse a space-delimited scope string and check access.
     */
    public function checkAccess(string $scopeString, string $resourceType, string $permission): bool
    {
        $parsed = [];
        foreach (explode(' ', $scopeString) as $raw) {
            $scope = SmartScope::parse(trim($raw));
            if ($scope !== null) {
                $parsed[] = $scope;
            }
        }

        return $this->isAllowed($parsed, $resourceType, $permission);
    }
}
