<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Enum;

use Pulsar\Api\Api;

/**
 * Impact assessment categories for AI systems.
 *
 * Per ISO 42001:2023 Clause 6.1.2 and Annex B, impact assessments
 * must evaluate AI systems across these dimensions.
 */
#[Api(since: '1.0.0')]
enum ImpactCategory: string
{
    case Fairness = 'fairness';
    case Transparency = 'transparency';
    case Accountability = 'accountability';
    case Privacy = 'privacy';
    case Safety = 'safety';
    case Security = 'security';
}
