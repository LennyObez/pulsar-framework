<?php

declare(strict_types=1);

namespace Pulsar\Extension\OAuth2\Contract;

use Pulsar\Api\Api;

/**
 * Resolves OIDC claims for a subject.
 *
 * Maps scopes to user profile claims for ID tokens and the UserInfo endpoint.
 * Applications implement this interface to provide user data from their domain model.
 *
 * Standard OIDC scope-to-claim mappings:
 * - openid: sub
 * - profile: name, family_name, given_name, middle_name, nickname, preferred_username,
 *            profile, picture, website, gender, birthdate, zoneinfo, locale, updated_at
 * - email: email, email_verified
 * - address: address
 * - phone: phone_number, phone_number_verified
 */
#[Api(since: '1.0.0')]
interface UserClaimsProviderInterface
{
    /**
     * Resolve claims for a subject based on granted scopes.
     *
     * @param string $subjectId The resource owner identifier
     * @param list<string> $scopes The granted scopes
     * @return array<string, mixed> Claim name => claim value
     */
    public function getClaims(string $subjectId, array $scopes): array;

    /**
     * Get the subject identifier for the given user in the context of a specific client.
     *
     * Allows pairwise subject identifiers per OIDC Core 8.1.
     *
     * @param string $userId The internal user identifier
     * @param string $clientId The OAuth2 client requesting the subject
     * @return string The subject identifier (may be pairwise or public)
     */
    public function getSubjectIdentifier(string $userId, string $clientId): string;
}
