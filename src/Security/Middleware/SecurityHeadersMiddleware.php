<?php

declare(strict_types=1);

namespace Pulsar\Security\Middleware;

use Override;
use Pulsar\Config\SecurityHeadersConfig;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\Request;
use Pulsar\Http\Response;

/**
 * Middleware that applies configured security headers to every response.
 *
 * Adds headers like X-Content-Type-Options, X-Frame-Options, Referrer-Policy,
 * CSP, Cross-Origin headers, and conditionally HSTS for secure requests.
 */
final readonly class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function __construct(
        private SecurityHeadersConfig $config,
    ) {}

    #[Override]
    public function process(Request $request, callable $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        foreach ($this->config->effectiveHeaders() as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        if ($this->config->hsts->enabled && $this->isSecureRequest($request)) {
            $response = $response->withHeader(
                'Strict-Transport-Security',
                $this->config->hsts->toHeaderValue(),
            );
        }

        return $response;
    }

    private function isSecureRequest(Request $request): bool
    {
        if ($request->isSecure()) {
            return true;
        }

        return $request->headers->first('X-Forwarded-Proto') === 'https';
    }
}
