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
     * Add middleware to the pipeline. It executes after every middleware added
     * so far (appended to the back, so it runs further inside the stack).
     *
     * @param PsrMiddlewareInterface|class-string<PsrMiddlewareInterface> $middleware
     */
    public function pipe(PsrMiddlewareInterface|string $middleware): MiddlewarePipelineInterface;

    /**
     * Add middleware to the FRONT of the pipeline, so it executes before every
     * middleware added so far (outermost). This lets a project or extension
     * place a middleware ahead of the framework's own — for example to observe
     * the request URI before a rewriting middleware (the locale prefix or slug
     * middleware) mutates it, which appending can never achieve.
     *
     * @param PsrMiddlewareInterface|class-string<PsrMiddlewareInterface> $middleware
     */
    public function prepend(PsrMiddlewareInterface|string $middleware): MiddlewarePipelineInterface;
}
