<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\Risk;

use Pulsar\Api\Api;

/**
 * The result of an adaptive risk assessment for a request.
 *
 * Exposed as the `risk.assessment` request attribute so downstream layers
 * (forms, the managed-challenge renderer) can apply adaptive friction — e.g.
 * present a challenge only when {@see $decision} is {@see RiskDecision::Challenge}.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class RiskAssessment
{
    public const string REQUEST_ATTRIBUTE = 'risk.assessment';

    /**
     * @param float $score Aggregated risk in [0.0, 1.0]
     * @param list<RiskSignal> $signals The contributing signals
     * @param bool $bypassed Whether a bypass provider short-circuited scoring
     */
    public function __construct(
        public float $score,
        public RiskDecision $decision,
        public array $signals = [],
        public bool $bypassed = false,
    ) {}
}
