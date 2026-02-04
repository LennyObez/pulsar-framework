<?php

declare(strict_types=1);

namespace Pulsar\ErrorHandling;

use Pulsar\Api\Api;
use Pulsar\Http\Request;
use Pulsar\Http\ResponseStatus;
use Throwable;

/**
 * Contract for rendering exceptions as response bodies.
 */
#[Api(since: '1.0.0')]
interface ExceptionRendererInterface
{
    /**
     * Render an exception as an HTML string.
     */
    public function render(Throwable $exception, Request $request, ResponseStatus $status): string;
}
