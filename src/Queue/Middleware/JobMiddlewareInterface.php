<?php

declare(strict_types=1);

namespace Pulsar\Queue\Middleware;

use Closure;
use Pulsar\Api\Api;
use Pulsar\Queue\Envelope\JobEnvelope;

/**
 * Contract for job middleware that intercepts envelope processing.
 *
 * Middleware forms a pipeline around job dispatch and execution.
 * Each middleware receives the envelope and a `$next` closure representing
 * the remainder of the pipeline. Middleware may modify the envelope,
 * short-circuit processing, or add pre/post behavior.
 * @api
 */
#[Api(since: '1.0.0')]
interface JobMiddlewareInterface
{
    /**
     * Process the job envelope through this middleware.
     *
     * @param Closure(JobEnvelope): mixed $next The next middleware or final handler.
     */
    public function handle(JobEnvelope $envelope, Closure $next): mixed;
}
