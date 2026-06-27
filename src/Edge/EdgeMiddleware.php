<?php

declare(strict_types=1);

namespace Pulsar\Edge;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Internal;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\TrustedProxy;
use Pulsar\Support\Coerce;

use function is_string;
use function rawurlencode;

/**
 * Runs the edge-function pipeline against incoming HTTP requests.
 *
 * Adapts the HTTP {@see ServerRequestInterface} into an {@see EdgeRequest},
 * runs the {@see EdgeFunctionPipeline}, and — if a function returns an
 * {@see EdgeResponse} (a redirect, block, etc.) — short-circuits the request by
 * converting it to an HTTP response. When every function passes (returns null),
 * the request continues to the application unchanged. Wired only when edge
 * functions are configured (see EdgeWiring).
 */
#[Internal]
final readonly class EdgeMiddleware implements MiddlewareInterface
{
    public function __construct(
        private EdgeFunctionPipeline $pipeline,
        private string $geoCountryHeader = 'CF-IPCountry',
        private ?TrustedProxy $trustedProxy = null,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $edgeResponse = $this->pipeline->process($this->toEdgeRequest($request));

        if ($edgeResponse !== null) {
            return $this->toHttpResponse($edgeResponse);
        }

        return $handler->handle($request);
    }

    private function toEdgeRequest(ServerRequestInterface $request): EdgeRequest
    {
        $uri = $request->getUri();
        $country = $request->getHeaderLine($this->geoCountryHeader);

        return new EdgeRequest(
            method: $request->getMethod(),
            url: (string) $uri,
            path: $uri->getPath(),
            headers: $this->flattenHeaders($request),
            cookies: Coerce::mapOfString($request->getCookieParams()),
            geo: $country !== '' ? ['country' => $country] : [],
            ip: $this->clientIp($request),
            userAgent: $request->getHeaderLine('User-Agent'),
        );
    }

    private function toHttpResponse(EdgeResponse $edge): ResponseInterface
    {
        $response = Response::text($edge->body, $edge->statusCode);

        foreach ($edge->headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        foreach ($edge->cookies as $name => $value) {
            $response = $response->withAddedHeader(
                'Set-Cookie',
                $name . '=' . rawurlencode($value) . '; Path=/; HttpOnly; SameSite=Lax',
            );
        }

        return $response;
    }

    /**
     * @return array<string, string>
     */
    private function flattenHeaders(ServerRequestInterface $request): array
    {
        /** @var array<string, string> $headers */
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[(string) $name] = $values[0] ?? '';
        }

        return $headers;
    }

    private function clientIp(ServerRequestInterface $request): string
    {
        if ($this->trustedProxy !== null) {
            return $this->trustedProxy->resolveClientIp($request);
        }

        /** @var mixed $ip */
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? '';

        return is_string($ip) ? $ip : '';
    }
}
