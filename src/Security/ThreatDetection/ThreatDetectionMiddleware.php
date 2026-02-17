<?php

declare(strict_types=1);

namespace Pulsar\Security\ThreatDetection;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Api;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\ResponseStatus;

/**
 * HTTP middleware that runs threat detection on incoming requests.
 *
 * Performs lightweight per-request analysis. When a threat is detected
 * with a Block response, returns 403 Forbidden. For Challenge responses,
 * returns 403 with a challenge indicator. Rate-limit and lower actions
 * allow the request through but attach a threat attribute for downstream
 * middleware to act on.
 *
 * Compliance: DORA Art.17, NIS2 Art.21(b), PCI-DSS Req.11.
 */
#[Api(since: '1.0.0')]
final readonly class ThreatDetectionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ThreatDetectionEngine $engine,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $threats = $this->engine->analyze($request);

        if ($threats === []) {
            return $handler->handle($request);
        }

        $action = ThreatDetectionEngine::highestSeverityAction($threats);

        return match ($action) {
            ThreatResponse::Block => Response::json(
                ['error' => 'Forbidden', 'reason' => 'threat_detected'],
                ResponseStatus::Forbidden->value,
            ),
            ThreatResponse::Challenge => Response::json(
                ['error' => 'Forbidden', 'reason' => 'challenge_required', 'challenge' => 'mfa'],
                ResponseStatus::Forbidden->value,
            ),
            default => $handler->handle(
                $request->withAttribute('threat_detection.threats', $threats),
            ),
        };
    }
}
