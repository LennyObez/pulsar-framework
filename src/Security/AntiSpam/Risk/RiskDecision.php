<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\Risk;

use Pulsar\Api\Api;

/**
 * The outcome of an adaptive risk assessment: the progressive-friction band a
 * request falls into.
 * @api
 */
#[Api(since: '1.0.0')]
enum RiskDecision: string
{
    /** Low risk: serve normally, no friction. */
    case Allow = 'allow';

    /** Elevated risk: a challenge should be presented before trusting the request. */
    case Challenge = 'challenge';

    /** High risk: reject the request. */
    case Block = 'block';
}
