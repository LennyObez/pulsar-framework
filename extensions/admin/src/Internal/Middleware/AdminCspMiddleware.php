<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Internal\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Http\Middleware\MiddlewareInterface;

use function bin2hex;
use function random_bytes;

/**
 * Content Security Policy middleware for admin panel HTML responses.
 *
 * Adds a strict CSP header with optional nonce-based script allowlisting.
 */
#[Internal]
final readonly class AdminCspMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AdminConfig $config,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $nonce = '';
        if ($this->config->security->cspNonce) {
            $nonce = bin2hex(random_bytes(16));
            $request = $request->withAttribute('csp_nonce', $nonce);
        }

        $response = $handler->handle($request);

        $scriptSrc = $nonce !== '' ? "'nonce-$nonce'" : "'self'";
        $csp = "default-src 'self'; script-src $scriptSrc; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'";

        return $response->withHeader('Content-Security-Policy', $csp);
    }
}
