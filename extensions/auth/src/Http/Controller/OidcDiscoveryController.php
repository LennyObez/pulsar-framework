<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Http\Controller;

use Pulsar\Api\Internal;
use Pulsar\Extension\Auth\OAuth2\Oidc\JwksEndpointInterface;
use Pulsar\Extension\Auth\OAuth2\Oidc\OidcDiscovery;
use Pulsar\Http\Message\Response;

/**
 * Route handlers for the two OIDC discovery documents.
 *
 * Both documents are derived from configuration and the public half of the
 * signing keyring, so they take no identity input and the extension can serve
 * them without anything from the host application.
 */
#[Internal(reason: 'Route handler')]
final readonly class OidcDiscoveryController
{
    public function __construct(
        private OidcDiscovery $discovery,
        private JwksEndpointInterface $jwksEndpoint,
    ) {}

    /**
     * GET /.well-known/openid-configuration — OpenID Connect Discovery 1.0 §4.
     */
    public function configuration(): Response
    {
        return Response::json($this->discovery->configurationDocument());
    }

    /**
     * GET /.well-known/jwks.json — RFC 7517 §5.
     */
    public function jwks(): Response
    {
        return Response::json($this->jwksEndpoint->jwksDocument());
    }
}
