<?php

declare(strict_types=1);

namespace Pulsar\Runtime;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Api;

/**
 * Contract for HTTP runtime implementations.
 *
 * Defines the lifecycle for both traditional PHP-FPM and persistent worker runtimes.
 */
#[Api(since: '1.0.0')]
interface RuntimeInterface
{
    /**
     * Start the runtime main loop (blocking for persistent, single-shot for FPM).
     */
    public function start(): void;

    /**
     * Signal graceful shutdown.
     */
    public function stop(): void;

    /**
     * Prepare the request sandbox before handling.
     */
    public function beforeRequest(ServerRequestInterface $request): ServerRequestInterface;

    /**
     * Clean up the request sandbox after handling.
     */
    public function afterRequest(ServerRequestInterface $request, ResponseInterface $response): void;
}
