<?php

declare(strict_types=1);

namespace Pulsar\ErrorHandling;

use Pulsar\Api\Api;

/**
 * Exceptions implementing this interface can report themselves.
 *
 * When the ExceptionHandler encounters a reportable exception,
 * it calls report() before the normal logging pipeline.
 * Return false from report() to suppress default logging.
 */
#[Api(since: '1.0.0')]
interface ReportableInterface
{
    /**
     * Report the exception.
     *
     * @return bool Return false to suppress default logging
     */
    public function report(): bool;
}
