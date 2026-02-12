<?php

declare(strict_types=1);

namespace Pulsar\Http\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Config\CorsConfig;
use Pulsar\Http\Message\Response;

use function implode;
use function in_array;
use function strtoupper;

/**
 * CORS middleware that handles preflight OPTIONS requests and adds
 * Access-Control-* headers to all responses based on CorsConfig.
 */
final readonly class CorsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private CorsConfig $config,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');

        // No Origin header — not a CORS request
        if ($origin === '') {
            return $handler->handle($request);
        }

        if (!$this->config->isOriginAllowed($origin)) {
            return $handler->handle($request);
        }

        // Handle preflight OPTIONS requests
        if (strtoupper($request->getMethod()) === 'OPTIONS' && $request->hasHeader('Access-Control-Request-Method')) {
            return $this->handlePreflight($origin);
        }

        // Actual request — add CORS headers to response
        $response = $handler->handle($request);

        return $this->addCorsHeaders($response, $origin);
    }

    private function handlePreflight(string $origin): ResponseInterface
    {
        $response = new Response(204);
        $response = $this->addCorsHeaders($response, $origin);

        if ($this->config->allowedMethods !== []) {
            $response = $response->withHeader(
                'Access-Control-Allow-Methods',
                implode(', ', $this->config->allowedMethods),
            );
        }

        if ($this->config->allowedHeaders !== []) {
            $response = $response->withHeader(
                'Access-Control-Allow-Headers',
                implode(', ', $this->config->allowedHeaders),
            );
        }

        if ($this->config->maxAge > 0) {
            $response = $response->withHeader('Access-Control-Max-Age', (string) $this->config->maxAge);
        }

        return $response;
    }

    private function addCorsHeaders(ResponseInterface $response, string $origin): ResponseInterface
    {
        $allowOrigin = in_array('*', $this->config->allowedOrigins, true) ? '*' : $origin;
        $response = $response->withHeader('Access-Control-Allow-Origin', $allowOrigin);

        if ($this->config->allowCredentials) {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        if ($this->config->exposedHeaders !== []) {
            $response = $response->withHeader(
                'Access-Control-Expose-Headers',
                implode(', ', $this->config->exposedHeaders),
            );
        }

        // Vary by Origin so caches distinguish per-origin responses
        if (!in_array('*', $this->config->allowedOrigins, true)) {
            $response = $response->withAddedHeader('Vary', 'Origin');
        }

        return $response;
    }
}
