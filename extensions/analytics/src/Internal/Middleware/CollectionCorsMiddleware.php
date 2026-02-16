<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Internal\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Analytics\Contracts\SiteRepositoryInterface;
use Pulsar\Http\Message\Response;

use function is_string;
use function parse_url;
use function str_starts_with;
use function substr;

use const PHP_URL_HOST;

/**
 * Validates Origin header against registered site domains and sets CORS headers.
 */
#[Internal(reason: 'Analytics middleware; CORS validation')]
final readonly class CollectionCorsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private SiteRepositoryInterface $siteRepository,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');

        // Preflight
        if ($request->getMethod() === 'OPTIONS') {
            return $this->preflightResponse($origin);
        }

        $response = $handler->handle($request);

        if ($origin === '') {
            return $response;
        }

        $allowedOrigin = $this->resolveAllowedOrigin($origin);

        if ($allowedOrigin === null) {
            return $response;
        }

        return $this->addCorsHeaders($response, $allowedOrigin);
    }

    private function resolveAllowedOrigin(string $origin): ?string
    {
        $host = parse_url($origin, PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            return null;
        }

        $site = $this->siteRepository->findByDomain($host);

        if ($site !== null) {
            return $origin;
        }

        // Also check without www prefix
        if (str_starts_with($host, 'www.')) {
            $site = $this->siteRepository->findByDomain(substr($host, 4));

            if ($site !== null) {
                return $origin;
            }
        }

        return null;
    }

    private function preflightResponse(string $origin): ResponseInterface
    {
        $allowedOrigin = $this->resolveAllowedOrigin($origin);

        if ($allowedOrigin === null) {
            return Response::noContent();
        }

        return $this->addCorsHeaders(Response::noContent(), $allowedOrigin)
            ->withHeader('Access-Control-Max-Age', '86400');
    }

    private function addCorsHeaders(ResponseInterface $response, string $origin): ResponseInterface
    {
        return $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Allow-Methods', 'POST')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type');
    }
}
