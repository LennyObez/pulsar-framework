<?php

declare(strict_types=1);

namespace Pulsar\Security\ThreatDetection;

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

use function is_string;
use function str_starts_with;

/**
 * Middleware that detects requests to honeypot paths.
 *
 * Registers fake routes that only attackers or scanners would hit
 * (e.g., /wp-login.php, /.env, /phpinfo.php). Any request to these
 * paths is logged to the audit trail, emits a ThreatEvent, and
 * optionally blocks the IP.
 *
 * Zero false positives: these paths are never valid in a Pulsar application.
 *
 * Compliance: DORA Art.17, NIS2 Art.21(b), PCI-DSS Req.11.
 */
#[Api(since: '1.0.0')]
final readonly class HoneypotMiddleware implements MiddlewareInterface
{
    public function __construct(
        private HoneypotConfig $config,
        private ?AuditLoggerInterface $auditLogger = null,
        private ?ThreatEventDispatcherInterface $eventDispatcher = null,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        if (!$this->isHoneypotPath($path)) {
            return $handler->handle($request);
        }

        $remoteAddr = $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown';
        $sourceIp = is_string($remoteAddr) ? $remoteAddr : 'unknown';

        $event = ThreatEvent::create(
            category: ThreatCategory::Reconnaissance,
            recommendedAction: $this->config->responseAction,
            sourceIp: $sourceIp,
            description: 'Honeypot endpoint triggered: ' . $path,
            confidence: 1.0,
            metadata: [
                'path' => $path,
                'method' => $request->getMethod(),
                'user_agent' => $request->getHeaderLine('User-Agent'),
            ],
        );

        $this->eventDispatcher?->dispatch($event);

        $this->auditLogger?->log(
            event: AuditEvent::SecurityEvent,
            outcome: AuditOutcome::Denied,
            actor: AuditActor::anonymous(),
            action: 'honeypot.triggered',
            resource: $path,
            metadata: [
                'source_ip' => $sourceIp,
                'method' => $request->getMethod(),
                'user_agent' => $request->getHeaderLine('User-Agent'),
            ],
        );

        if ($this->config->blockIp) {
            return Response::json(
                ['error' => 'Forbidden'],
                ResponseStatus::Forbidden->value,
            );
        }

        // Return a realistic 404 to avoid tipping off the attacker
        return Response::json(
            ['error' => 'Not Found'],
            ResponseStatus::NotFound->value,
        );
    }

    private function isHoneypotPath(string $path): bool
    {
        foreach ($this->config->paths as $honeypot) {
            if ($path === $honeypot || str_starts_with($path, $honeypot . '/')) {
                return true;
            }
        }

        return false;
    }
}
