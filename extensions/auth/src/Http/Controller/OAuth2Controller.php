<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Http\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Auth\OAuth2\Contract\AuthorizationServerInterface;

/**
 * Route handlers for the OAuth2 authorization server endpoints.
 *
 * The server contract is PSR-7 in and PSR-7 out, so every action is a straight
 * delegation. The class exists because a router needs a handler it can resolve
 * and invoke, and an interface name is not one.
 */
#[Internal(reason: 'Route handler; depend on AuthorizationServerInterface')]
final readonly class OAuth2Controller
{
    public function __construct(
        private AuthorizationServerInterface $server,
    ) {}

    /**
     * GET /oauth/authorize — RFC 6749 §3.1.
     */
    public function authorize(ServerRequestInterface $request): ResponseInterface
    {
        return $this->server->handleAuthorizationRequest($request);
    }

    /**
     * POST /oauth/token — RFC 6749 §3.2.
     */
    public function token(ServerRequestInterface $request): ResponseInterface
    {
        return $this->server->handleTokenRequest($request);
    }

    /**
     * POST /oauth/introspect — RFC 7662.
     */
    public function introspect(ServerRequestInterface $request): ResponseInterface
    {
        return $this->server->handleIntrospectionRequest($request);
    }

    /**
     * POST /oauth/revoke — RFC 7009.
     */
    public function revoke(ServerRequestInterface $request): ResponseInterface
    {
        return $this->server->handleRevocationRequest($request);
    }
}
