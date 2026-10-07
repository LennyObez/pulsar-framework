<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psr7Bridge\Adapter;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Api;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;

use function is_array;

/**
 * Converts a PSR-7 ServerRequestInterface into a Pulsar Request.
 *
 * @deprecated Since 1.0.0-rc.11. Pulsar now uses PSR-7 natively: no conversion needed.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class Psr7ToPulsarRequest
{
    /**
     * Convert a PSR-7 ServerRequestInterface to a Pulsar Request.
     */
    public function convert(ServerRequestInterface $psrRequest): Request
    {
        $method = Method::fromString($psrRequest->getMethod());
        $uri = (string) $psrRequest->getUri();

        $path = $psrRequest->getUri()->getPath();
        $queryString = $psrRequest->getUri()->getQuery();

        /** @var array<string, string|list<string>> $headerMap */
        $headerMap = $psrRequest->getHeaders();
        $headers = new HeaderBag($headerMap);

        $body = (string) $psrRequest->getBody();

        /** @var array<string, mixed> $query */
        $query = $psrRequest->getQueryParams();

        $parsedBody = $psrRequest->getParsedBody();
        /** @var array<string, mixed> $post */
        $post = is_array($parsedBody) ? $parsedBody : [];

        /** @var array<string, mixed> $cookies */
        $cookies = $psrRequest->getCookieParams();

        /** @var array<string, mixed> $server */
        $server = $psrRequest->getServerParams();

        /** @var array<string, mixed> $attributes */
        $attributes = $psrRequest->getAttributes();

        return new Request(
            method: $method,
            uri: $uri,
            path: $path,
            queryString: $queryString,
            headers: $headers,
            body: $body,
            query: $query,
            post: $post,
            cookies: $cookies,
            server: $server,
            attributes: $attributes,
            protocolVersion: $psrRequest->getProtocolVersion(),
        );
    }
}
