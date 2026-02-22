<?php

declare(strict_types=1);

namespace Pulsar\Tests\Benchmark\Support;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Auth\SecurityContext;
use Pulsar\Http\Middleware\MiddlewareInterface;

/**
 * Benchmark token resolver middleware.
 *
 * Resolves the bearer token from the request and attaches the identity.
 * Uses StubTokenResolver for Tier A (returns fixed identity).
 */
final class BenchTokenResolverMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly StubTokenResolver $tokenResolver,
        private readonly StubAuthManager $authManager,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $token = $this->extractBearerToken($request);
        $identity = $token !== null
            ? $this->tokenResolver->resolve($token)
            : null;

        if ($identity !== null) {
            $securityContext = new SecurityContext($this->authManager, $request);
            $request = $request->withAttribute('_security_context', $securityContext);
            $request = $request->withAttribute('_identity', $identity);
        }

        return $handler->handle($request);
    }

    private function extractBearerToken(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine('Authorization');

        if (str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }

        return null;
    }
}
