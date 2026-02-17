<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psr7Bridge\Adapter;

use Psr\Http\Message\ResponseInterface;
use Pulsar\Api\Api;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;

/**
 * Converts a PSR-7 ResponseInterface into a Pulsar Response.
 *
 * @deprecated Since 1.0.0-rc.11. Pulsar now uses PSR-7 natively: no conversion needed.
 */
#[Api(since: '1.0.0')]
final readonly class Psr7ToPulsarResponse
{
    /**
     * Convert a PSR-7 ResponseInterface to a Pulsar Response.
     */
    public function convert(ResponseInterface $psrResponse): Response
    {
        $status = ResponseStatus::from($psrResponse->getStatusCode());

        /** @var array<string, string|list<string>> $headerMap */
        $headerMap = $psrResponse->getHeaders();
        $headers = new HeaderBag($headerMap);

        $body = (string) $psrResponse->getBody();

        return new Response(
            body: $body,
            status: $status,
            headers: $headers,
            protocolVersion: $psrResponse->getProtocolVersion(),
        );
    }
}
