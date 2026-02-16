<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Auth;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Authorization\PolicyContext;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Http\Message\Response;

/**
 * Shared authorization helpers for CMS API controllers.
 *
 * Provides identity extraction and permission checking against the
 * framework's GateInterface. Controllers using this trait must
 * declare a `$gate` property of type GateInterface.
 */
#[Internal(reason: 'CMS API authorization trait; implementation detail')]
trait RequiresAuthorization
{
    /**
     * Extract the authenticated identity from the request.
     *
     * Returns a 401 JSON response if no authenticated identity is present.
     */
    private function requireIdentity(ServerRequestInterface $request): IdentityInterface|Response
    {
        /** @var IdentityInterface|null $identity */
        $identity = $request->getAttribute('identity');

        if ($identity === null || !$identity->isAuthenticated()) {
            return Response::json(['error' => 'Authentication required', 'status' => 401], 401);
        }

        return $identity;
    }

    /**
     * Extract the identity if present, or null for unauthenticated requests.
     */
    private function optionalIdentity(ServerRequestInterface $request): ?IdentityInterface
    {
        /** @var IdentityInterface|null $identity */
        $identity = $request->getAttribute('identity');

        if ($identity === null || !$identity->isAuthenticated()) {
            return null;
        }

        return $identity;
    }

    /**
     * Check if the identity has the given permission.
     *
     * Returns a 403 JSON response if denied, or null if allowed.
     */
    private function authorize(
        GateInterface $gate,
        IdentityInterface $identity,
        CmsPermission $permission,
        ?string $resource = null,
    ): ?Response {
        $context = new PolicyContext(
            permission: $permission->value,
            resource: $resource,
        );

        if ($gate->denies($identity, $permission->value, $context)) {
            return Response::json(['error' => 'Forbidden', 'status' => 403], 403);
        }

        return null;
    }

    /**
     * Verify a content item belongs to the authenticated user's tenant.
     *
     * Returns a 403 response if the tenant doesn't match, null if OK.
     */
    private function verifyTenantAccess(?string $resourceTenantId, ?string $identityTenantId): ?Response
    {
        if ($identityTenantId === null) {
            return null;
        }

        if ($resourceTenantId !== $identityTenantId) {
            return Response::json(['error' => 'Forbidden', 'status' => 403], 403);
        }

        return null;
    }
}
