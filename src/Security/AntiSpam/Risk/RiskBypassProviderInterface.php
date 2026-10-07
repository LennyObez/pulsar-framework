<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\Risk;

use NoDiscard;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Api;

/**
 * Short-circuits risk assessment for trusted requests.
 *
 * When any bypass provider returns true the engine resolves to
 * {@see RiskDecision::Allow} without scoring — used by attestation mechanisms
 * such as Private Access Tokens (Privacy Pass), where a valid token proves a
 * legitimate client and should skip the challenge.
 * @api
 */
#[Api(since: '1.0.0')]
interface RiskBypassProviderInterface
{
    #[NoDiscard]
    public function shouldBypass(ServerRequestInterface $request): bool;
}
