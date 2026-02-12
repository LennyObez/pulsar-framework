<?php

declare(strict_types=1);

namespace Pulsar\Http\Factory;

use NoDiscard;
use Override;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Pulsar\Api\Api;
use Pulsar\Http\Message\Stream;

/**
 * PSR-17 stream factory.
 */
#[Api(since: '1.0.0-rc.11')]
final class StreamFactory implements StreamFactoryInterface
{
    #[NoDiscard]
    #[Override]
    public function createStream(string $content = ''): StreamInterface
    {
        return Stream::create($content);
    }

    #[NoDiscard]
    #[Override]
    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        return Stream::fromFile($filename, $mode);
    }

    /**
     * @param resource $resource
     */
    #[NoDiscard]
    #[Override]
    public function createStreamFromResource($resource): StreamInterface
    {
        return new Stream($resource);
    }
}
