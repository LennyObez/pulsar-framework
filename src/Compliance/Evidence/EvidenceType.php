<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Evidence;

use Pulsar\Api\Api;

/**
 * Types of compliance evidence that can be collected.
 * @api
 */
#[Api(since: '1.0.0')]
enum EvidenceType: string
{
    case Configuration = 'configuration';
    case AuditLog = 'audit_log';
    case TestResult = 'test_result';
    case AccessControl = 'access_control';
    case Encryption = 'encryption';
    case Monitoring = 'monitoring';
    case ChangeManagement = 'change_management';
    case Vulnerability = 'vulnerability';
    case Sbom = 'sbom';
}
