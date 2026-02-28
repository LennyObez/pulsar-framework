<?php

declare(strict_types=1);

namespace Pulsar\Security\Dlp;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Api;
use Pulsar\Audit\AuditActor;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\Stream;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\ResponseStatus;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

use function count;
use function strlen;

/**
 * HTTP middleware that scans outgoing response bodies for sensitive data.
 *
 * When sensitive data is detected, the configured DLP action determines
 * the response: redact the content, block the response entirely, or
 * allow with an audit alert.
 *
 * Compliance: PCI-DSS Req.3/4, HIPAA §164.312(e), GDPR Art.32.
 */
#[Api(since: '1.0.0')]
final readonly class DlpScanMiddleware implements MiddlewareInterface
{
    public function __construct(
        private SensitivePatternRegistry $registry,
        private DlpConfig $config,
        private AuditLoggerInterface $auditLogger,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if (!$this->config->enabled || !$this->config->scanResponses) {
            return $response;
        }

        $body = (string) $response->getBody();

        if ($body === '') {
            return $response;
        }

        $result = $this->registry->scan($body);

        if (!$result->detected) {
            return $response;
        }

        $this->auditLogger->log(
            event: AuditEvent::SecurityEvent,
            outcome: AuditOutcome::Denied,
            actor: AuditActor::system('security.dlp'),
            action: 'dlp.sensitive_data_detected',
            resource: $request->getUri()->getPath(),
            metadata: [
                'match_count' => count($result->matches),
                'types' => array_unique(array_map(
                    static fn(DlpMatch $m): string => $m->type->value,
                    $result->matches,
                )),
                'action' => $result->actionTaken->value,
            ],
        );

        return match ($this->config->defaultAction) {
            DlpAction::Block => Response::json(
                ['error' => 'Response blocked by data loss prevention policy'],
                ResponseStatus::InternalServerError->value,
            ),
            DlpAction::Redact => $this->replaceBody($response, $result->redactedContent),
            DlpAction::Alert => $response,
        };
    }

    private function replaceBody(ResponseInterface $response, string $content): ResponseInterface
    {
        return $response
            ->withBody(Stream::create($content))
            ->withHeader('Content-Length', (string) strlen($content));
    }
}
