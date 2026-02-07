<?php

declare(strict_types=1);

namespace Pulsar\Tests\Benchmark\Support;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditLogger;
use Pulsar\Security\Audit\AuditOutcome;

/**
 * Benchmark audit writer middleware.
 *
 * Writes an audit entry for each request via AuditLogger (HMAC-signed).
 */
final class BenchAuditWriterMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        $this->auditLogger->log(
            event: AuditEvent::DataAccess,
            outcome: AuditOutcome::Success,
            actor: 'bench-user-1',
            action: 'api.request',
            resource: $request->getUri()->getPath(),
            metadata: ['method' => $request->getMethod()],
        );

        return $response;
    }
}
