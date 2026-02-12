<?php

declare(strict_types=1);

namespace Pulsar\Queue\Middleware;

use Closure;
use Pulsar\Api\Internal;
use Pulsar\Queue\Envelope\JobEnvelope;

use function array_reverse;

/**
 * Executes a chain of job middleware around a final handler.
 *
 * Middleware is applied in FIFO order: the first middleware added is the
 * outermost layer. The pipeline builds a nested closure chain and invokes
 * it with the given envelope.
 */
#[Internal(reason: 'Pipeline orchestration is an implementation detail of the queue system')]
final readonly class MiddlewarePipeline
{
    /**
     * @param list<JobMiddlewareInterface> $middleware Middleware in execution order.
     */
    public function __construct(
        private array $middleware = [],
    ) {}

    /**
     * Send the envelope through the middleware chain, terminating with the given handler.
     *
     * @param Closure(JobEnvelope): mixed $destination The final handler after all middleware.
     */
    public function process(JobEnvelope $envelope, Closure $destination): mixed
    {
        $pipeline = array_reverse($this->middleware);

        /** @var Closure(JobEnvelope): mixed $next */
        $next = $destination;

        foreach ($pipeline as $layer) {
            $next = static fn(JobEnvelope $e): mixed => $layer->handle($e, $next);
        }

        return $next($envelope);
    }
}
