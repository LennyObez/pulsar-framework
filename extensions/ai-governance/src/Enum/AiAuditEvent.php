<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Enum;

use Pulsar\Api\Api;

/**
 * AI-specific auditable events for governance tracking.
 *
 * Extends the core AuditEvent taxonomy with AI-specific event types
 * required by ISO 42001:2023 Clause 9.1 monitoring and measurement.
 * @api
 */
#[Api(since: '1.0.0')]
enum AiAuditEvent: string
{
    case ModelInvoked = 'ai.model_invoked';
    case DecisionMade = 'ai.decision_made';
    case HumanOverride = 'ai.human_override';
    case BiasDetected = 'ai.bias_detected';
    case ModelDeployed = 'ai.model_deployed';
    case ModelRetired = 'ai.model_retired';
    case ImpactAssessed = 'ai.impact_assessed';
    case DataProvenanceRecorded = 'ai.data_provenance_recorded';
    case TransparencyReportGenerated = 'ai.transparency_report_generated';
    case DeploymentGatePassed = 'ai.deployment_gate_passed';
    case DeploymentGateFailed = 'ai.deployment_gate_failed';
}
