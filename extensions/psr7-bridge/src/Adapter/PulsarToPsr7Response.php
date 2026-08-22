<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psr7Bridge\Adapter;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Pulsar\Api\Api;
use Pulsar\Http\Response;

/**
 * Converts a Pulsar Response into a PSR-7 ResponseInterface.
 *
 * Uses Nyholm/PSR-7 as the concrete PSR-7 implementation.
 *
 * @deprecated Since 1.0.0-rc.11. Pulsar now uses PSR-7 natively: no conversion needed.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class PulsarToPsr7Response
{
    private Psr17Factory $factory;

    public function __construct()
    {
        $this->factory = new Psr17Factory();
    }

    /**
     * Convert a Pulsar Response to a PSR-7 ResponseInterface.
     */
    public function convert(Response $response): ResponseInterface
    {
        $psrResponse = $this->factory->createResponse(
            $response->status->value,
            $response->status->reasonPhrase(),
        );

        foreach ($response->headers->toArray() as $name => $values) {
            $psrResponse = $psrResponse->withHeader($name, $values);
        }

        $psrResponse = $psrResponse->withBody(
            $this->factory->createStream($response->body),
        );

        return $psrResponse->withProtocolVersion($response->protocolVersion);
    }
}
