<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Internal\Middleware;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
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
    public function process(Request $request, callable $next): Response
    {
        if (!$this->config->enabled) {
            return Response::json(
                ['error' => 'Admin panel is disabled'],
                ResponseStatus::NotFound,
            );
        }

        return $next($request);
    }
}
