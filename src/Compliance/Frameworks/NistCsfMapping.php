<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Frameworks;

use Pulsar\Api\Internal;
use Pulsar\Compliance\Control;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Compliance\ControlStatus;

/**
 * Registers NIST Cybersecurity Framework 2.0 controls into the catalog.
 *
 * Maps Pulsar framework features to the six NIST CSF 2.0 functions:
 * Govern, Identify, Protect, Detect, Respond, Recover.
 */
#[Internal(reason: 'Framework-internal control registration; use ControlCatalog for public access')]
final class NistCsfMapping
{
    /**
     * Register NIST CSF 2.0 controls into the given catalog.
     */
    public static function register(ControlCatalog $catalog): void
    {
        // --- GV: Govern ---

        $catalog->register(new Control(
            id: 'NIST-GV.OC',
            framework: 'nist_csf',
            title: 'Govern: Organizational Context (GV.OC)',
            description: 'Understand the organizational mission, stakeholder expectations, and legal/regulatory '
                . 'requirements that inform cybersecurity risk management. Covered by the compliance '
                . 'profile resolver which aggregates regulatory requirements across enabled frameworks.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['compliance_profile', 'compliance_verification', 'extension_registry'],
        ));

        $catalog->register(new Control(
            id: 'NIST-GV.RM',
            framework: 'nist_csf',
            title: 'Govern: Risk Management Strategy (GV.RM)',
            description: 'Establish and communicate the organization\'s risk management strategy. Covered by '
                . 'configurable compliance profiles, security configuration defaults, and the '
                . 'most-restrictive-wins resolution strategy across regulatory frameworks.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['compliance_profile', 'security_config', 'data_classification'],
        ));

        // --- ID: Identify ---

        $catalog->register(new Control(
            id: 'NIST-ID.AM',
            framework: 'nist_csf',
            title: 'Identify: Asset Management (ID.AM)',
            description: 'Maintain inventories of hardware, software, services, and data flows. Covered by '
                . 'extension registry, service discovery, and API snapshot tooling that catalog '
                . 'deployed components and their public interfaces.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['extension_registry', 'service_discovery', 'api_snapshot'],
        ));

        $catalog->register(new Control(
            id: 'NIST-ID.RA',
            framework: 'nist_csf',
            title: 'Identify: Risk Assessment (ID.RA)',
            description: 'Understand the cybersecurity risk to the organization. Covered by the compliance '
                . 'verification engine, FIPS compliance checks, and boundary enforcement that '
                . 'continuously validate the security posture.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['compliance_verification', 'fips_validation', 'boundary_check'],
        ));

        // --- PR: Protect ---

        $catalog->register(new Control(
            id: 'NIST-PR.AA',
            framework: 'nist_csf',
            title: 'Protect: Identity Management, Authentication, and Access Control (PR.AA)',
            description: 'Manage identities, credentials, and access. Covered by authentication middleware, '
                . 'RBAC, MFA support, session management with idle timeouts, and CSRF protection.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['authentication', 'rbac', 'mfa', 'session_management', 'csrf_protection'],
        ));

        $catalog->register(new Control(
            id: 'NIST-PR.DS',
            framework: 'nist_csf',
            title: 'Protect: Data Security (PR.DS)',
            description: 'Protect data confidentiality, integrity, and availability. Covered by encryption '
                . 'at rest (AES-256-GCM / XSalsa20-Poly1305), TLS enforcement, tokenization, '
                . 'input validation, and output escaping.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['crypto_keyring', 'envelope_encryption', 'tokenization', 'tls_enforcement', 'input_validation'],
        ));

        $catalog->register(new Control(
            id: 'NIST-PR.PS',
            framework: 'nist_csf',
            title: 'Protect: Platform Security (PR.PS)',
            description: 'Manage the security of hardware, software, and services. Covered by security headers '
                . 'middleware (HSTS, CSP, COOP/COEP/CORP), rate limiting, and secure configuration defaults.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['security_headers', 'rate_limiting', 'security_config'],
        ));

        // --- DE: Detect ---

        $catalog->register(new Control(
            id: 'NIST-DE.CM',
            framework: 'nist_csf',
            title: 'Detect: Continuous Monitoring (DE.CM)',
            description: 'Monitor assets to find anomalies, indicators of compromise, and other adverse events. '
                . 'Covered by structured audit logging, queue health monitoring, and OpenTelemetry '
                . 'instrumentation for metrics, traces, and logs.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['audit_logging', 'queue_monitoring', 'opentelemetry', 'health_checks'],
        ));

        $catalog->register(new Control(
            id: 'NIST-DE.AE',
            framework: 'nist_csf',
            title: 'Detect: Adverse Event Analysis (DE.AE)',
            description: 'Analyze detected anomalies to understand attack techniques and impact. Covered by '
                . 'tamper-evident HMAC-chained audit logs, compliance event system, and incident '
                . 'reporting infrastructure.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['hmac_chain', 'compliance_events', 'incident_reporting'],
        ));

        // --- RS: Respond ---

        $catalog->register(new Control(
            id: 'NIST-RS.MA',
            framework: 'nist_csf',
            title: 'Respond: Incident Management (RS.MA)',
            description: 'Manage and coordinate incident response. Covered by the incident reporter, breach '
                . 'notification subsystem, and compliance event logging that supports regulated '
                . 'notification deadlines.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['incident_reporting', 'breach_notification', 'compliance_events', 'audit_logging'],
        ));

        // --- RC: Recover ---

        $catalog->register(new Control(
            id: 'NIST-RC.RP',
            framework: 'nist_csf',
            title: 'Recover: Incident Recovery Plan Execution (RC.RP)',
            description: 'Execute the recovery portion of the incident response plan. Framework provides health '
                . 'check infrastructure, worker restart mechanisms, and deployment primitives. Actual '
                . 'backup and disaster recovery procedures are the deployer\'s responsibility.',
            status: ControlStatus::Partial,
            frameworkFeatures: ['health_checks', 'deployment', 'queue_monitoring'],
        ));
    }
}
