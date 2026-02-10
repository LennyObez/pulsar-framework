<?php

declare(strict_types=1);

namespace Pulsar\Http\Factory;

use NoDiscard;
use Override;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Pulsar\Api\Api;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Message\Uri;

use function is_string;

/**
 * PSR-17 request factory.
 *
 * Creates ServerRequest instances since Pulsar only uses server-side requests.
 */
#[Api(since: '1.0.0-rc.11')]
final class RequestFactory implements RequestFactoryInterface
{
    #[NoDiscard]
    #[Override]
    public function createRequest(string $method, $uri): RequestInterface
    {
        $uriInstance = is_string($uri) ? Uri::fromString($uri) : $uri;

        return new ServerRequest(method: $method, uri: $uriInstance);
    }
}
