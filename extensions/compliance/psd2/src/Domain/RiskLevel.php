<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Domain;

use Pulsar\Api\Api;

/**
 * Transaction risk classification per PSD2 RTS Art. 18.
 * @api
 */
#[Api(since: '1.0.0')]
enum RiskLevel: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
