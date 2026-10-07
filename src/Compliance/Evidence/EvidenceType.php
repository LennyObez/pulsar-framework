<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Evidence;

use Pulsar\Api\Api;

/**
 * Types of compliance evidence that can be collected.
 *
 * The vocabulary an {@see EvidenceRecord}'s free-string `type` is drawn from.
 * {@see \Pulsar\Compliance\Verification\EvidenceChain} writes
 * {@see self::VerificationRun}; the remaining cases name the kinds of evidence an
 * application records for its own controls.
 * @api
 */
#[Api(since: '1.0.0')]
enum EvidenceType: string
{
    /**
     * One run of the compliance verification engine.
     *
     * The value is `verification_evidence` rather than the case name because
     * that string is already sitting in deployments' evidence files, and an
     * enum case is not worth rewriting a tamper-evident record for.
     */
    case VerificationRun = 'verification_evidence';

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
