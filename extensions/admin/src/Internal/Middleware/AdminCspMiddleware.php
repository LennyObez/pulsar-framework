<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Internal\Middleware;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\Request;
use Pulsar\Http\Response;

use function bin2hex;
use function random_bytes;

/**
 * Content Security Policy middleware for admin panel HTML responses.
 *
 * Adds a strict CSP header with optional nonce-based script allowlisting.
 */
#[Internal]
final class AdminCspMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AdminConfig $config,
    ) {}

    #[Override]
    public function process(Request $request, callable $next): Response
    {
        $nonce = '';
        if ($this->config->security->cspNonce) {
            $nonce = bin2hex(random_bytes(16));
            $request = $request->withAttribute('csp_nonce', $nonce);
        }

        $response = $next($request);

        $scriptSrc = $nonce !== '' ? "'nonce-{$nonce}'" : "'self'";
        $csp = "default-src 'self'; script-src {$scriptSrc}; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'";

        return $response->withHeader('Content-Security-Policy', $csp);
    }
}
