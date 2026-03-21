<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Enum;

use Pulsar\Api\Api;

/**
 * Risk classification for AI models per EU AI Act alignment.
 *
 * ISO 42001:2023 Clause 6.1.2 requires risk assessment. These levels
 * align with the EU AI Act tiered risk framework.
 * @api
 */
#[Api(since: '1.0.0')]
enum AiModelRiskLevel: string
{
    case Minimal = 'minimal';
    case Limited = 'limited';
    case High = 'high';
    case Unacceptable = 'unacceptable';
}
