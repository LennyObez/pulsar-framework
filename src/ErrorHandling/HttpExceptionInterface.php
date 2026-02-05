<?php

declare(strict_types=1);

namespace Pulsar\ErrorHandling;

use Pulsar\Api\Api;
use Pulsar\Http\ResponseStatus;

/**
 * Contract for exceptions that map to HTTP status codes.
 */
#[Api]
interface HttpExceptionInterface
{
    /**
     * Get the HTTP status code for this exception.
     */
    public function getStatusCode(): ResponseStatus;

    /**
     * Get additional HTTP headers for the response.
     *
     * @return array<string, string>
     */
    public function getHeaders(): array;
}
