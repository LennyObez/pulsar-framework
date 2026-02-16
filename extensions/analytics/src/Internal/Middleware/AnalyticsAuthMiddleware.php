<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Internal\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Identity\AnonymousIdentity;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewareInterface;

/**
 * Authentication and authorization guard for analytics dashboard and API routes.
 *
 * Requires an authenticated (non-anonymous) identity. Permission checking
 * is delegated to the framework's GateInterface (RBAC+ABAC) rather than
 * relying on duck typing.
 */
#[Internal(reason: 'Analytics auth middleware; route-level guard')]
final readonly class AnalyticsAuthMiddleware implements MiddlewareInterface
{
    private const string DEFAULT_PERMISSION = 'analytics.view';

    public function __construct(
        private ?GateInterface $gate = null,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $identity = $request->getAttribute('identity');

        // No identity attached or anonymous: require authentication
        if (!$identity instanceof IdentityInterface || $identity instanceof AnonymousIdentity) {
            return Response::json(['error' => 'Authentication required'], 401);
        }

        // Check permission via the authorization gate (RBAC+ABAC)
        if ($this->gate !== null && $this->gate->denies($identity, self::DEFAULT_PERMISSION)) {
            return Response::json(['error' => 'Insufficient permissions'], 403);
        }

        return $handler->handle($request);
    }
}
