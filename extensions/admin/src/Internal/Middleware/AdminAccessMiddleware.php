<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Internal\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\ResponseStatus;

/**
 * Checks that the admin panel is enabled before allowing requests through.
 */
#[Internal]
final readonly class AdminAccessMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AdminConfig $config,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->config->enabled) {
            return Response::json(
                ['error' => 'Admin panel is disabled'],
                ResponseStatus::NotFound->value,
            );
        }

        return $handler->handle($request);
    }
}
