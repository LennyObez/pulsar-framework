<?php

declare(strict_types=1);

namespace Pulsar\Http\Middleware;

use Psr\Http\Server\MiddlewareInterface as PsrMiddlewareInterface;
use Pulsar\Api\Api;

/**
 * Public contract for piping middleware into the global HTTP pipeline.
 *
 * Extensions use this interface to add middleware without depending on
 * the concrete MiddlewarePipeline implementation.
 * @api
 */
#[Api(since: '1.0.0')]
interface MiddlewarePipelineInterface
{
    /**
     * Add middleware to the pipeline.
     *
     * @param PsrMiddlewareInterface|class-string<PsrMiddlewareInterface> $middleware
     */
    public function pipe(PsrMiddlewareInterface|string $middleware): MiddlewarePipelineInterface;
}
