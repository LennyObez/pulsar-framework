<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\Risk;

use Pulsar\Api\Api;

/**
 * A single risk contribution from one signal provider.
 *
 * The score is a probability-like value in [0.0, 1.0] (0 = no indication of
 * abuse, 1 = certain abuse). The source labels the provider for observability.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class RiskSignal
{
    public function __construct(
        public float $score,
        public string $source,
    ) {}
}
