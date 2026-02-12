<?php

declare(strict_types=1);

namespace Pulsar\Http\Factory;

use NoDiscard;
use Override;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;
use Pulsar\Api\Api;
use Pulsar\Http\Message\Uri;

/**
 * PSR-17 URI factory.
 */
#[Api(since: '1.0.0-rc.11')]
final class UriFactory implements UriFactoryInterface
{
    #[NoDiscard]
    #[Override]
    public function createUri(string $uri = ''): UriInterface
    {
        return Uri::fromString($uri);
    }
}
