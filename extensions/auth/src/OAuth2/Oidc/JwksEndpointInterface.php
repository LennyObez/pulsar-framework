<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\OAuth2\Oidc;

use Pulsar\Api\Internal;

/**
 * Publishes the JSON Web Key Set relying parties verify tokens against.
 *
 * Internal, but named: the controller has to be able to depend on something it
 * can stand in for. A deployment that serves its keys from a hardware module or
 * a shared cache substitutes an implementation here; with a final class in the
 * signature there was no seam, and no way to assert the controller's behaviour
 * without a real keyring either.
 */
#[Internal(reason: 'Seam for the discovery controller; not part of the public surface')]
interface JwksEndpointInterface
{
    /**
     * The JWKS document to serve at the discovery endpoint.
     *
     * @return array{keys: list<array<string, string>>}
     */
    public function jwksDocument(): array;
}
