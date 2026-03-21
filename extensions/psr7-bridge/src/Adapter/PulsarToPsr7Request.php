<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psr7Bridge\Adapter;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Api;
use Pulsar\Http\Request;

/**
 * Converts a Pulsar Request into a PSR-7 ServerRequestInterface.
 *
 * Uses Nyholm/PSR-7 as the concrete PSR-7 implementation.
 *
 * @deprecated Since 1.0.0-rc.11. Pulsar now uses PSR-7 natively: no conversion needed.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class PulsarToPsr7Request
{
    private Psr17Factory $factory;

    public function __construct()
    {
        $this->factory = new Psr17Factory();
    }

    /**
     * Convert a Pulsar Request to a PSR-7 ServerRequestInterface.
     */
    public function convert(Request $request): ServerRequestInterface
    {
        $uri = $this->factory->createUri($request->uri);

        $psrRequest = new ServerRequest(
            method: $request->method->value,
            uri: $uri,
            headers: $request->headers->toArray(),
            body: $this->factory->createStream($request->body),
            version: $request->protocolVersion,
            serverParams: $request->server,
        );

        $psrRequest = $psrRequest
            ->withQueryParams($request->query)
            ->withCookieParams($request->cookies)
            ->withParsedBody($request->post !== [] ? $request->post : null);

        foreach ($request->attributes as $name => $value) {
            $psrRequest = $psrRequest->withAttribute($name, $value);
        }

        return $psrRequest;
    }
}
