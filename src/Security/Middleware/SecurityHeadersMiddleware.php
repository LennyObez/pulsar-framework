<?php

declare(strict_types=1);

namespace Pulsar\Security\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Config\SecurityHeadersConfig;
use Pulsar\Http\Middleware\MiddlewareInterface;

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
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

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

    private function isSecureRequest(ServerRequestInterface $request): bool
    {
        if ($request->getUri()->getScheme() === 'https') {
            return true;
        }

        return $request->getHeaderLine('X-Forwarded-Proto') === 'https';
    }
}
