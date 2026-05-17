<?php

declare(strict_types=1);

namespace Pulsar\Security\ApiSigning;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Api;
use Pulsar\Audit\AuditActor;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\ResponseStatus;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Security\ThreatDetection\ThreatCategory;
use Pulsar\Security\ThreatDetection\ThreatEvent;
use Pulsar\Security\ThreatDetection\ThreatEventDispatcherInterface;
use Pulsar\Security\ThreatDetection\ThreatResponse;
use SodiumException;

use function is_string;

/**
 * Middleware that verifies API request signatures.
 *
 * Rejects unsigned or tampered requests with 401 Unauthorized.
 * Logs signature failures to the audit trail and dispatches threat events.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class RequestSignatureMiddleware implements MiddlewareInterface
{
    public function __construct(
        private RequestSigner $signer,
        private ?AuditLoggerInterface $auditLogger = null,
        private ?ThreatEventDispatcherInterface $eventDispatcher = null,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            $result = $this->signer->verify($request);
        } catch (SodiumException) {
            return Response::json(
                ['error' => 'Signature verification error'],
                ResponseStatus::InternalServerError->value,
            );
        }

        if (!$result->valid) {
            $remoteAddr = $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown';
            $sourceIp = is_string($remoteAddr) ? $remoteAddr : 'unknown';

            $this->auditLogger?->log(
                event: AuditEvent::SecurityEvent,
                outcome: AuditOutcome::Denied,
                actor: AuditActor::anonymous(),
                action: 'api_signing.verification_failed',
                resource: $request->getUri()->getPath(),
                metadata: [
                    'reason' => $result->reason,
                    'source_ip' => $sourceIp,
                    'method' => $request->getMethod(),
                ],
            );

            $this->eventDispatcher?->dispatch(ThreatEvent::create(
                category: ThreatCategory::RequestTampering,
                recommendedAction: ThreatResponse::Block,
                sourceIp: $sourceIp,
                description: 'API request signature verification failed: ' . $result->reason,
                confidence: 0.9,
                metadata: [
                    'path' => $request->getUri()->getPath(),
                    'reason' => $result->reason,
                ],
            ));

            return Response::json(
                ['error' => 'Unauthorized', 'reason' => 'invalid_signature'],
                ResponseStatus::Unauthorized->value,
            );
        }

        return $handler->handle($request);
    }
}
