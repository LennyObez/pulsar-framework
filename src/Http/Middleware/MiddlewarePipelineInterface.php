<?php

declare(strict_types=1);

namespace Pulsar\Http\Middleware;

use Pulsar\Api\Api;

/**
 * Public contract for piping middleware into the global HTTP pipeline.
 *
 * Extensions use this interface to add middleware without depending on
 * the concrete MiddlewarePipeline implementation.
 */
#[Api]
interface MiddlewarePipelineInterface
{
    /**
     * Add middleware to the pipeline.
     *
     * @param MiddlewareInterface|class-string<MiddlewareInterface> $middleware
     */
    public function pipe(MiddlewareInterface|string $middleware): static;
}
