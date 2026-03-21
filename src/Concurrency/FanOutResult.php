<?php

declare(strict_types=1);

namespace Pulsar\Concurrency;

use Pulsar\Api\Api;
use Throwable;

/**
 * Outcome of a single task executed via {@see FanOut::run()}.
 *
 * Each result captures the success/failure state, the returned value (if any),
 * the exception (if any), and whether the task was terminated due to timeout.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class FanOutResult
{
    public function __construct(
        public bool $success,
        public mixed $value = null,
        public ?Throwable $error = null,
        public bool $timedOut = false,
    ) {}
}
