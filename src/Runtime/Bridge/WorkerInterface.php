<?php

declare(strict_types=1);

namespace Pulsar\Runtime\Bridge;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Api;

/**
 * Contract for a PSR-7 worker that receives and responds to requests.
 *
 * Decouples the runtime adapter from concrete PSR-7 worker implementations
 * (e.g., RoadRunner, ReactPHP). The concrete implementation bridges the
 * external worker's protocol.
 * @api
 */
#[Api(since: '1.0.0')]
interface WorkerInterface
{
    /**
     * Wait for the next incoming request.
     *
     * Returns null when the worker should stop (e.g., supervisor signal).
     */
    public function waitRequest(): ?ServerRequestInterface;

    /**
     * Send a PSR-7 response back to the client.
     */
    public function respond(ResponseInterface $response): void;
}
