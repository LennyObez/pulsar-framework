<?php

declare(strict_types=1);

namespace Pulsar\FeatureFlag;

use Pulsar\Api\Api;

/**
 * Reason for a feature flag evaluation result.
 * @api
 */
#[Api(since: '1.0.0')]
enum FlagEvaluationReason: string
{
    case FlagDisabled = 'flag_disabled';
    case FlagEnabled = 'flag_enabled';
    case FlagNotFound = 'flag_not_found';
    case DefaultState = 'default_state';
    case TenantMatch = 'tenant_match';
    case UserMatch = 'user_match';
    case EnvironmentMatch = 'environment_match';
    case PercentageRollout = 'percentage_rollout';
    case PercentageExcluded = 'percentage_excluded';
}
