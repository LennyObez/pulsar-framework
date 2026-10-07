<?php

declare(strict_types=1);

namespace Pulsar\Runtime\Bridge;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Api;
use Pulsar\Http\Request;
use Pulsar\Http\Response;

/**
 * Converts between PSR-7 messages and Pulsar's HTTP objects.
 *
 * Used by RoadRunner adapter to bridge external PSR-7 workers to Pulsar's
 * internal HTTP types when needed (e.g., health endpoint responses).
 * @api
 */
#[Api(since: '1.0.0')]
interface PsrBridgeInterface
{
    /**
     * Convert a PSR-7 server request to a Pulsar Request.
     */
    public function toPulsarRequest(ServerRequestInterface $psrRequest): Request;

    /**
     * Convert a Pulsar Response to a PSR-7 response.
     */
    public function toPsrResponse(Response $pulsarResponse): ResponseInterface;
}
