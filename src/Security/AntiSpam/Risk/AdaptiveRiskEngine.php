<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\Risk;

use NoDiscard;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Api;

use function max;
use function min;

/**
 * Aggregates risk signals for a request and resolves a progressive-friction
 * decision.
 *
 * Bypass providers are consulted first: any valid attestation (e.g. a Private
 * Access Token) short-circuits to {@see RiskDecision::Allow}. Otherwise each
 * signal provider contributes an independent probability, combined as
 * `1 - ∏(1 - sᵢ)` (probabilistic OR — corroborating weak signals raise the
 * score, a single strong signal dominates), then thresholded into the decision.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AdaptiveRiskEngine
{
    /**
     * @param list<RiskSignalProviderInterface> $signalProviders
     * @param list<RiskBypassProviderInterface> $bypassProviders
     */
    public function __construct(
        private AdaptiveRiskConfig $config,
        private array $signalProviders = [],
        private array $bypassProviders = [],
    ) {}

    #[NoDiscard]
    public function assess(ServerRequestInterface $request): RiskAssessment
    {
        foreach ($this->bypassProviders as $bypass) {
            if ($bypass->shouldBypass($request)) {
                return new RiskAssessment(0.0, RiskDecision::Allow, [], bypassed: true);
            }
        }

        $signals = [];
        $survivingProbability = 1.0;

        foreach ($this->signalProviders as $provider) {
            $signal = $provider->evaluate($request);
            $signals[] = $signal;
            $survivingProbability *= 1.0 - $this->clamp($signal->score);
        }

        $score = 1.0 - $survivingProbability;

        return new RiskAssessment($score, $this->decide($score), $signals);
    }

    private function decide(float $score): RiskDecision
    {
        if ($score >= $this->config->blockThreshold) {
            return RiskDecision::Block;
        }

        if ($score >= $this->config->challengeThreshold) {
            return RiskDecision::Challenge;
        }

        return RiskDecision::Allow;
    }

    private function clamp(float $score): float
    {
        return max(0.0, min(1.0, $score));
    }
}
