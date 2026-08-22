<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Enum;

use Pulsar\Api\Api;

/**
 * Lifecycle status of an AI model within the governance framework.
 *
 * Follows ISO 42001:2023 Clause 8.4 model lifecycle management.
 * @api
 */
#[Api(since: '1.0.0')]
enum AiModelStatus: string
{
    case Development = 'development';
    case Testing = 'testing';
    case Staging = 'staging';
    case Production = 'production';
    case Deprecated = 'deprecated';
    case Retired = 'retired';
}
