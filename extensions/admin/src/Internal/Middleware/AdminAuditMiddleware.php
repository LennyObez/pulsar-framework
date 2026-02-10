<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Internal\Middleware;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

use function in_array;

/**
 * Audit logging middleware for admin operations.
 *
 * Logs every admin request as either a DataAccess or DataModification event.
 */
#[Internal]
final readonly class AdminAuditMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AuditLoggerInterface $auditLogger,
    ) {}

    #[Override]
    public function process(Request $request, callable $next): Response
    {
        $response = $next($request);

        /** @var IdentityInterface|null $identity */
        $identity = $request->attribute('identity');
        $actor = $identity?->id() ?? 'anonymous';

        $event = $this->isMutation($request)
            ? AuditEvent::DataModification
            : AuditEvent::DataAccess;

        $outcome = $response->status->isError()
            ? AuditOutcome::Failure
            : AuditOutcome::Success;

        $this->auditLogger->log(
            event: $event,
            outcome: $outcome,
            actor: $actor,
            action: "admin.{$request->method->value}.$request->path",
            resource: $request->path,
            metadata: [
                'method' => $request->method->value,
                'status' => $response->status->value,
                'ip' => $request->server('REMOTE_ADDR'),
            ],
        );

        return $response;
    }

    private function isMutation(Request $request): bool
    {
        return in_array($request->method->value, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }
}
