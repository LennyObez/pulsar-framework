<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Internal;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewareInterface;

/**
 * Content negotiation middleware for the CMS REST API.
 *
 * Ensures all responses carry JSON content type, adds CORS headers,
 * and handles OPTIONS preflight requests with a 204 No Content response.
 */
#[Internal(reason: 'CMS API content negotiation — middleware implementation')]
final readonly class CmsApiContentNegotiationMiddleware implements MiddlewareInterface
{
    private const string ALLOWED_METHODS = 'GET, POST, PUT, DELETE, OPTIONS';
    private const string ALLOWED_HEADERS = 'Content-Type, Authorization, X-API-Key';

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Handle OPTIONS preflight
        if ($request->getMethod() === 'OPTIONS') {
            return new Response(204)
                ->withHeader('Access-Control-Allow-Origin', '*')
                ->withHeader('Access-Control-Allow-Methods', self::ALLOWED_METHODS)
                ->withHeader('Access-Control-Allow-Headers', self::ALLOWED_HEADERS);
        }

        $response = $handler->handle($request);

        return $response
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Methods', self::ALLOWED_METHODS)
            ->withHeader('Access-Control-Allow-Headers', self::ALLOWED_HEADERS);
    }
}
