<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\Risk;

use NoDiscard;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Api;

/**
 * Contributes one risk signal for a request.
 *
 * Implementations are composed by {@see AdaptiveRiskEngine}; the framework
 * ships a bot-score provider, and JA4 fingerprinting, reputation, and other
 * signals plug in here without changing the engine.
 * @api
 */
#[Api(since: '1.0.0')]
interface RiskSignalProviderInterface
{
    #[NoDiscard]
    public function evaluate(ServerRequestInterface $request): RiskSignal;
}
