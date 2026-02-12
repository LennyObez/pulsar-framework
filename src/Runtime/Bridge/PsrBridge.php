<?php

declare(strict_types=1);

namespace Pulsar\Runtime\Bridge;

use Closure;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;
use Pulsar\Http\Response;

use function implode;
use function is_array;
use function rawurldecode;

/**
 * Default PSR-7 to Pulsar HTTP bridge.
 *
 * Converts PSR-7 ServerRequestInterface to Pulsar Request and vice versa.
 * Uses a response factory closure to create PSR-7 Response objects,
 * keeping the bridge decoupled from any concrete PSR-7 implementation.
 *
 * Used by RoadRunner adapter to bridge external PSR-7 workers to Pulsar's
 * internal HTTP types when needed (e.g., health endpoint responses).
 */
#[Internal]
final readonly class PsrBridge implements PsrBridgeInterface
{
    /**
     * @param Closure(int, string, array<string, string>): ResponseInterface $responseFactory
     */
    public function __construct(
        private Closure $responseFactory,
    ) {}

    #[Override]
    public function toPulsarRequest(ServerRequestInterface $psrRequest): Request
    {
        $uri = $psrRequest->getUri();
        $method = Method::fromString($psrRequest->getMethod());

        $fullUri = (string) $uri;
        $path = $uri->getPath() ?: '/';
        $path = rawurldecode($path);

        $queryString = $uri->getQuery();

        // Parse query string into array
        $query = $psrRequest->getQueryParams();

        // Build headers map (flatten multi-value headers with comma join)
        /** @var array<string, string> $headers */
        $headers = [];
        foreach ($psrRequest->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }

        $body = (string) $psrRequest->getBody();
        /** @var array<string, mixed> $cookies */
        $cookies = $psrRequest->getCookieParams();
        /** @var array<string, mixed> $serverParams */
        $serverParams = $psrRequest->getServerParams();

        // Carry over PSR-7 parsed body as POST data
        $parsedBody = $psrRequest->getParsedBody();
        /** @var array<string, mixed> $post */
        $post = is_array($parsedBody) ? $parsedBody : [];

        $protocolVersion = $psrRequest->getProtocolVersion();

        /** @var array<string, mixed> $typedQuery */
        $typedQuery = $query;

        /** @var array<string, string> $flatHeaders */
        $flatHeaders = $headers;

        return new Request(
            method: $method,
            uri: $fullUri,
            path: $path,
            queryString: $queryString,
            headers: new HeaderBag($flatHeaders),
            body: $body,
            query: $typedQuery,
            post: $post,
            cookies: $cookies,
            server: $serverParams,
            protocolVersion: $protocolVersion,
        );
    }

    #[Override]
    public function toPsrResponse(Response $pulsarResponse): ResponseInterface
    {
        $headers = [];
        foreach ($pulsarResponse->headers->toArray() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }

        return ($this->responseFactory)(
            $pulsarResponse->status->value,
            $pulsarResponse->body,
            $headers,
        );
    }
}
