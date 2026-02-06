<?php

declare(strict_types=1);

namespace Pulsar\Http\Factory;

use NoDiscard;
use Override;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Pulsar\Api\Api;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Message\Uri;

use function is_string;

/**
 * PSR-17 server request factory.
 */
#[Api(since: '1.0.0-rc.11')]
final class ServerRequestFactory implements ServerRequestFactoryInterface
{
    /**
     * @param string|UriInterface $uri
     * @param array<array-key, mixed> $serverParams
     */
    #[NoDiscard]
    #[Override]
    public function createServerRequest(string $method, $uri, array $serverParams = []): ServerRequestInterface
    {
        $uriInstance = is_string($uri) ? Uri::fromString($uri) : $uri;

        /** @var array<string, mixed> $serverParams */
        return new ServerRequest(
            method: $method,
            uri: $uriInstance,
            serverParams: $serverParams,
        );
    }

    /**
     * Create a ServerRequest from PHP superglobals.
     */
    #[NoDiscard]
    public static function fromGlobals(): ServerRequest
    {
        return ServerRequest::fromGlobals();
    }
}
