<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Verification;

use Pulsar\Api\Api;

/**
 * Categorizes compliance checks by security domain.
 * @api
 */
#[Api(since: '1.0.0')]
enum ComplianceCheckDomain: string
{
    case Encryption = 'encryption';
    case Authentication = 'authentication';
    case SessionManagement = 'session_management';
    case AuditLogging = 'audit_logging';
    case AccessControl = 'access_control';
    case TransportSecurity = 'transport_security';
    case InputValidation = 'input_validation';
    case RateLimiting = 'rate_limiting';
    case DataRetention = 'data_retention';
    case IncidentResponse = 'incident_response';
}
