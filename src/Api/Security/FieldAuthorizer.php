<?php

declare(strict_types=1);

namespace Pulsar\Api\Security;

use Pulsar\Api\Api;
use Pulsar\Api\Resource\FieldPolicy;

/**
 * Per-field authorization checking against a clearance snapshot.
 *
 * Evaluates field policies against the requester's permissions, roles, and
 * classification clearance. Fields that fail authorization are either denied
 * (omitted silently) or marked for redaction.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class FieldAuthorizer
{
    /**
     * Determine the authorization result for a field.
     *
     * Checks in order:
     * 1. Classification clearance: denied if clearance is below field classification
     * 2. Required permissions; denied if any required permission is missing
     * 3. Required roles; denied if no matching role found (when roles are specified)
     */
    public function authorize(FieldPolicy $policy, ClearanceSnapshot $clearance): FieldAuthorizationResult
    {
        // Classification check
        if (!$clearance->meetsClassification($policy->classification)) {
            // Partial access possible if requester is authenticated
            if ($clearance->authenticated) {
                return FieldAuthorizationResult::Redacted;
            }

            return FieldAuthorizationResult::Denied;
        }

        // Permission check: all required permissions must be present
        if (array_any($policy->requiredPermissions, static fn(string $permission): bool => !$clearance->hasPermission($permission))) {
            return FieldAuthorizationResult::Denied;
        }

        // Role check: any matching role grants access
        if ($policy->requiredRoles !== [] && !$clearance->hasAnyRole($policy->requiredRoles)) {
            return FieldAuthorizationResult::Denied;
        }

        return FieldAuthorizationResult::Allowed;
    }
}
