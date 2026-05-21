<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Server\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Studio\Security\StudioAccessGate;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\ResponseStatus;

/**
 * Middleware that applies Studio access control per-request.
 *
 * Returns 401 for authentication failures (with WWW-Authenticate header),
 * 403 for authorization failures.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal]
final readonly class StudioAccessMiddleware implements MiddlewareInterface
{
    public function __construct(
        private StudioAccessGate $gate,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $result = $this->gate->check($request);

        if (!$result['allowed']) {
            if ($result['reason'] === 'Authentication required') {
                return new Response(
                    statusCode: ResponseStatus::Unauthorized->value,
                    headers: ['WWW-Authenticate' => 'Basic realm="Pulsar Studio"'],
                    body: 'Authentication required',
                );
            }

            return new Response(
                statusCode: ResponseStatus::Forbidden->value,
                body: $result['reason'] ?? 'Access denied',
            );
        }

        return $handler->handle($request);
    }
}
