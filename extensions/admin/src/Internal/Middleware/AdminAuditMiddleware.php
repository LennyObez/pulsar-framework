<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Internal\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\ResponseStatus;
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
        private AuditLoggerInterface $auditLogger,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        /** @var IdentityInterface|null $identity */
        $identity = $request->getAttribute('identity');
        $actor = $identity?->id() ?? 'anonymous';

        $method = $request->getMethod();
        $path = $request->getUri()->getPath();

        $event = $this->isMutation($method)
            ? AuditEvent::DataModification
            : AuditEvent::DataAccess;

        $statusCode = $response->getStatusCode();
        $status = ResponseStatus::tryFrom($statusCode);
        $outcome = ($status !== null && $status->isError())
            ? AuditOutcome::Failure
            : AuditOutcome::Success;

        $this->auditLogger->log(
            event: $event,
            outcome: $outcome,
            actor: $actor,
            action: "admin.$method.$path",
            resource: $path,
            metadata: [
                'method' => $method,
                'status' => $statusCode,
                'ip' => $request->getServerParams()['REMOTE_ADDR'] ?? null,
            ],
        );

        return $response;
    }

    private function isMutation(string $method): bool
    {
        return in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }
}
