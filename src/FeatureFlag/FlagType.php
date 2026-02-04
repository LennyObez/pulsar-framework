<?php

declare(strict_types=1);

namespace Pulsar\FeatureFlag;

/**
 * Types of feature flag evaluation.
 */
enum FlagType: string
{
    case Boolean = 'boolean';
    case Percentage = 'percentage';
    case Contextual = 'contextual';
}
