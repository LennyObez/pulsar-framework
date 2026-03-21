<?php

declare(strict_types=1);

namespace Pulsar\FeatureFlag;

use Pulsar\Api\Api;

/**
 * Types of feature flag evaluation.
 * @api
 */
#[Api(since: '1.0.0')]
enum FlagType: string
{
    case Boolean = 'boolean';
    case Percentage = 'percentage';
    case Contextual = 'contextual';
}
