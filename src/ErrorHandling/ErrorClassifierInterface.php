<?php

declare(strict_types=1);

namespace Pulsar\ErrorHandling;

use Pulsar\Api\Api;
use Throwable;

/**
 * Classifies exceptions into error categories for response handling.
 */
#[Api(since: '1.0.0-rc.11')]
interface ErrorClassifierInterface
{
    /**
     * Classify an exception into an error category.
     */
    public function classify(Throwable $exception): ErrorClassification;
}
