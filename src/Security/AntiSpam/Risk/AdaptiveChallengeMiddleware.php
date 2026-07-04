<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\Risk;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\ResponseStatus;

/**
 * Global middleware that applies the adaptive risk decision.
 *
 * High-risk requests are rejected (403); lower-risk requests pass through with
 * the {@see RiskAssessment} attached as a request attribute so downstream
 * layers (forms, the managed-challenge renderer) can present a challenge only
 * when the decision is {@see RiskDecision::Challenge} — progressive friction
 * instead of an always-on challenge. The risk score is intentionally not
 * leaked in a response header.
 */
#[Internal]
final readonly class AdaptiveChallengeMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AdaptiveRiskEngine $engine,
        private ?LoggerInterface $logger = null,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $assessment = $this->engine->assess($request);

        if ($assessment->decision === RiskDecision::Block) {
            $this->logger?->info('Request blocked by adaptive risk engine', ['score' => $assessment->score]);

            return Response::json(
                ['error' => 'Access denied'],
                ResponseStatus::Forbidden->value,
            );
        }

        return $handler->handle($request->withAttribute(RiskAssessment::REQUEST_ATTRIBUTE, $assessment));
    }
}
