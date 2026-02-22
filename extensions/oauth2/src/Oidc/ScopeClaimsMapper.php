<?php

declare(strict_types=1);

namespace Pulsar\Extension\OAuth2\Oidc;

use Pulsar\Api\Internal;

use function array_key_exists;
use function array_keys;

/**
 * Maps OIDC scopes to their corresponding claim names.
 *
 * Per OpenID Connect Core 1.0, Section 5.4:
 * - openid: sub (always included)
 * - profile: name, family_name, given_name, middle_name, nickname,
 *            preferred_username, profile, picture, website, gender,
 *            birthdate, zoneinfo, locale, updated_at
 * - email: email, email_verified
 * - address: address
 * - phone: phone_number, phone_number_verified
 */
#[Internal(reason: 'Implementation detail')]
final class ScopeClaimsMapper
{
    /** @var array<string, list<string>> */
    private const array SCOPE_CLAIMS = [
        'openid' => ['sub'],
        'profile' => [
            'name', 'family_name', 'given_name', 'middle_name',
            'nickname', 'preferred_username', 'profile', 'picture',
            'website', 'gender', 'birthdate', 'zoneinfo', 'locale',
            'updated_at',
        ],
        'email' => ['email', 'email_verified'],
        'address' => ['address'],
        'phone' => ['phone_number', 'phone_number_verified'],
    ];

    /**
     * Get the claim names authorized by the given scopes.
     *
     * @param list<string> $scopes
     * @return list<string>
     */
    public static function claimsForScopes(array $scopes): array
    {
        $seen = [];

        foreach ($scopes as $scope) {
            if (isset(self::SCOPE_CLAIMS[$scope])) {
                foreach (self::SCOPE_CLAIMS[$scope] as $claim) {
                    $seen[$claim] = true;
                }
            }
        }

        return array_keys($seen);
    }

    /**
     * Filter a claims array to only include claims authorized by the given scopes.
     *
     * @param array<string, mixed> $allClaims All available claims for the user
     * @param list<string> $scopes The granted scopes
     * @return array<string, mixed> Filtered claims
     */
    public static function filterClaims(array $allClaims, array $scopes): array
    {
        $allowedClaims = self::claimsForScopes($scopes);
        $filtered = [];

        foreach ($allowedClaims as $claimName) {
            if (array_key_exists($claimName, $allClaims)) {
                $filtered[$claimName] = $allClaims[$claimName];
            }
        }

        return $filtered;
    }
}
