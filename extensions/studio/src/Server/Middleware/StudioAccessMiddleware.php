<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Server\Middleware;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Studio\Security\StudioAccessGate;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;

/**
 * Middleware that applies Studio access control per-request.
 *
 * Returns 401 for authentication failures (with WWW-Authenticate header),
 * 403 for authorization failures.
 */
#[Internal]
final readonly class StudioAccessMiddleware implements MiddlewareInterface
{
    public function __construct(
        private StudioAccessGate $gate,
    ) {}

    #[Override]
    public function process(Request $request, callable $next): Response
    {
        $result = $this->gate->check($request);

        if (!$result['allowed']) {
            if ($result['reason'] === 'Authentication required') {
                return new Response(
                    body: 'Authentication required',
                    status: ResponseStatus::Unauthorized,
                    headers: new HeaderBag(['WWW-Authenticate' => 'Basic realm="Pulsar Studio"']),
                );
            }

            return new Response(
                body: $result['reason'] ?? 'Access denied',
                status: ResponseStatus::Forbidden,
            );
        }

        return $next($request);
    }
}
