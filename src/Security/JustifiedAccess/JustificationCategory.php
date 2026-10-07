<?php

declare(strict_types=1);

namespace Pulsar\Security\JustifiedAccess;

use Pulsar\Api\Api;

/**
 * Predefined categories for access justifications.
 *
 * Used to classify the purpose of sensitive data access for compliance
 * reporting and anomaly detection. Custom categories can be added via
 * configuration.
 * @api
 */
#[Api(since: '1.0.0')]
enum JustificationCategory: string
{
    case CustomerRequest = 'customer_request';
    case RegulatoryObligation = 'regulatory';
    case InternalAudit = 'audit';
    case DisputeResolution = 'dispute';
    case AccountMaintenance = 'maintenance';
    case Emergency = 'emergency';
    case LawEnforcement = 'law_enforcement';
    case FraudInvestigation = 'fraud_investigation';
}
