<?php

declare(strict_types=1);

namespace Pulsar\ErrorHandling;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Api;

/**
 * Exceptions implementing this interface can render their own HTTP response.
 *
 * When the ExceptionHandler encounters a renderable exception, it delegates
 * response creation to the exception itself, bypassing the default renderer.
 * @api
 */
#[Api(since: '1.0.0')]
interface RenderableInterface
{
    /**
     * Render the exception into an HTTP response.
     */
    public function render(ServerRequestInterface $request): ResponseInterface;
}
