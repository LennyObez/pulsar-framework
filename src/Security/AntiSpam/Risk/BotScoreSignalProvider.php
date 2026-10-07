<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\Risk;

use Override;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Security\ThreatDetection\BotDetector;

/**
 * Adapts the transparent {@see BotDetector} (header/User-Agent heuristics,
 * scored 0–100) into a normalised risk signal in [0.0, 1.0].
 */
#[Internal]
final readonly class BotScoreSignalProvider implements RiskSignalProviderInterface
{
    public function __construct(
        private BotDetector $detector,
    ) {}

    #[Override]
    public function evaluate(ServerRequestInterface $request): RiskSignal
    {
        return new RiskSignal($this->detector->analyze($request)->score / 100.0, 'bot_detector');
    }
}
