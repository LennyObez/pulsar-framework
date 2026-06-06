<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Frameworks;

use Pulsar\Api\Internal;
use Pulsar\Compliance\Control;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Compliance\ControlStatus;

/**
 * Registers SWIFT Customer Security Programme (CSP) controls into the catalog.
 *
 * Maps Pulsar security features to SWIFT CSP CSCF v2024 mandatory and
 * advisory controls across seven control objectives.
 *
 * @see https://www.swift.com/myswift/customer-security-programme-csp
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal(reason: 'Framework-internal control registration; use ControlCatalog for public access')]
final class SwiftCspMapping
{
    /**
     * Register SWIFT CSP controls into the given catalog.
     */
    public static function register(ControlCatalog $catalog): void
    {
        // === 1. Restrict Internet Access and Protect Critical Systems ===

        $catalog->register(new Control(
            id: 'SWIFT-1.1',
            framework: 'swift_csp',
            title: 'SWIFT Environment Protection (Mandatory)',
            description: 'Ensure the protection of the local SWIFT infrastructure from '
                . 'potentially compromised elements of the general IT environment and '
                . 'external environment. Network segmentation and access controls.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['network_segmentation', 'firewall_rules', 'trust_proxy_configuration'],
        ));

        $catalog->register(new Control(
            id: 'SWIFT-1.2',
            framework: 'swift_csp',
            title: 'Operating System Privileged Account Control (Mandatory)',
            description: 'Restrict and control the allocation and usage of administrator-level '
                . 'operating system accounts. Map to RBAC and privileged account management.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['rbac', 'privileged_account_management', 'role_separation'],
        ));

        $catalog->register(new Control(
            id: 'SWIFT-1.3',
            framework: 'swift_csp',
            title: 'Virtualisation and Cloud Platform Protection (Mandatory)',
            description: 'Secure virtualisation and cloud platforms hosting SWIFT-related '
                . 'components with the same level of protection as physical infrastructure.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['infrastructure_security', 'container_isolation', 'cloud_security'],
        ));

        $catalog->register(new Control(
            id: 'SWIFT-1.4',
            framework: 'swift_csp',
            title: 'Restriction of Internet Access (Mandatory)',
            description: 'Protect against cyber threats through restriction of internet access '
                . 'from operator PCs and systems within the secure zone.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['egress_filtering', 'proxy_configuration', 'url_filtering'],
        ));

        $catalog->register(new Control(
            id: 'SWIFT-1.5A',
            framework: 'swift_csp',
            title: 'Intrusion Detection (Advisory)',
            description: 'Detect anomalous activity on systems or transaction records. '
                . 'Map to Pulsar metrics, audit logging, and anomaly detection.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['anomaly_detection', 'audit_logging', 'metrics_collection'],
        ));

        // === 2. Reduce Attack Surface and Vulnerabilities ===

        $catalog->register(new Control(
            id: 'SWIFT-2.1',
            framework: 'swift_csp',
            title: 'Internal Data Flow Security (Mandatory)',
            description: 'Ensure the confidentiality, integrity, and mutual authenticity of '
                . 'data flows between application components within the secure zone.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['tls_enforcement', 'mutual_authentication', 'data_flow_encryption'],
        ));

        $catalog->register(new Control(
            id: 'SWIFT-2.2',
            framework: 'swift_csp',
            title: 'Security Updates (Mandatory)',
            description: 'Minimise the occurrence of known technical vulnerabilities on '
                . 'operator PCs and within the local SWIFT infrastructure by ensuring '
                . 'vendor support and applying mandatory security updates.',
            status: ControlStatus::Partial,
            frameworkFeatures: ['dependency_management', 'vulnerability_scanning', 'patch_tracking'],
        ));

        $catalog->register(new Control(
            id: 'SWIFT-2.3',
            framework: 'swift_csp',
            title: 'System Hardening (Mandatory)',
            description: 'Reduce the cyber attack surface of SWIFT-related components by '
                . 'performing system hardening. Disable unnecessary services and ports.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['security_headers', 'hsts', 'content_security_policy', 'service_hardening'],
        ));

        $catalog->register(new Control(
            id: 'SWIFT-2.4A',
            framework: 'swift_csp',
            title: 'Back-Office Data Flow Security (Advisory)',
            description: 'Ensure the confidentiality, integrity, and mutual authenticity of '
                . 'data flows between back-office applications and SWIFT infrastructure.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['api_authentication', 'request_signing', 'transport_encryption'],
        ));

        $catalog->register(new Control(
            id: 'SWIFT-2.5A',
            framework: 'swift_csp',
            title: 'External Transmission Data Protection (Advisory)',
            description: 'Protect the confidentiality of SWIFT-related data transmitted or '
                . 'stored outside the secure zone.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['encryption_at_rest', 'encryption_in_transit', 'data_classification'],
        ));

        $catalog->register(new Control(
            id: 'SWIFT-2.6',
            framework: 'swift_csp',
            title: 'Operator Session Confidentiality and Integrity (Mandatory)',
            description: 'Protect the confidentiality and integrity of interactive operator '
                . 'sessions connecting to the local SWIFT infrastructure.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['session_management', 'session_encryption', 'csrf_protection'],
        ));

        $catalog->register(new Control(
            id: 'SWIFT-2.7',
            framework: 'swift_csp',
            title: 'Vulnerability Scanning (Mandatory)',
            description: 'Identify known vulnerabilities within the local SWIFT environment '
                . 'by implementing a regular vulnerability scanning process.',
            status: ControlStatus::Partial,
            frameworkFeatures: ['vulnerability_scanning', 'dependency_audit', 'security_testing'],
        ));

        $catalog->register(new Control(
            id: 'SWIFT-2.8A',
            framework: 'swift_csp',
            title: 'Critical Activity Outsourcing (Advisory)',
            description: 'Ensure protection of the local SWIFT infrastructure when critical '
                . 'activity is outsourced to third-party service providers.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['third_party_risk', 'vendor_management', 'contract_compliance'],
        ));

        $catalog->register(new Control(
            id: 'SWIFT-2.9A',
            framework: 'swift_csp',
            title: 'Transaction Business Controls (Advisory)',
            description: 'Restrict transaction activity to validated and approved business. '
                . 'Map to transaction monitoring and authorization controls.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['transaction_monitoring', 'authorization_controls', 'business_rules'],
        ));

        // === 3. Physically Secure the Environment ===

        $catalog->register(new Control(
            id: 'SWIFT-3.1',
            framework: 'swift_csp',
            title: 'Physical Security (Mandatory)',
            description: 'Prevent unauthorised physical access to sensitive equipment, '
                . 'hosting sites, and storage. Physical security is outside software scope.',
            status: ControlStatus::NotApplicable,
            frameworkFeatures: ['physical_access_control'],
        ));

        // === 4. Prevent Compromise of Credentials ===

        $catalog->register(new Control(
            id: 'SWIFT-4.1',
            framework: 'swift_csp',
            title: 'Password Policy (Mandatory)',
            description: 'Ensure passwords are sufficiently resistant against common password '
                . 'attacks. Minimum 12 characters, complexity requirements, history.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['password_policy', 'password_complexity', 'password_history'],
        ));

        $catalog->register(new Control(
            id: 'SWIFT-4.2',
            framework: 'swift_csp',
            title: 'Multi-Factor Authentication (Mandatory)',
            description: 'Prevent that a compromised single authentication factor allows '
                . 'access to SWIFT systems. MFA for all SWIFT-related access.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['mfa', 'webauthn', 'totp', 'authentication_factors'],
        ));

        $catalog->register(new Control(
            id: 'SWIFT-4.3A',
            framework: 'swift_csp',
            title: 'Token and Credential Management (Advisory)',
            description: 'Ensure proper protection of authentication tokens, API keys, '
                . 'and other credentials throughout their lifecycle.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['credential_rotation', 'token_management', 'secret_storage'],
        ));

        // === 5. Manage Identities and Segregate Privileges ===

        $catalog->register(new Control(
            id: 'SWIFT-5.1',
            framework: 'swift_csp',
            title: 'Logical Access Control (Mandatory)',
            description: 'Enforce the security principles of need-to-know access, least '
                . 'privilege, and separation of duties. Map to RBAC and ACL.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['rbac', 'acl', 'least_privilege', 'separation_of_duties'],
        ));

        $catalog->register(new Control(
            id: 'SWIFT-5.2',
            framework: 'swift_csp',
            title: 'Token Management (Mandatory)',
            description: 'Ensure the proper management, tracking, and use of connected '
                . 'hardware authentication tokens (operator-level token management).',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['hardware_token_management', 'token_lifecycle', 'token_revocation'],
        ));

        $catalog->register(new Control(
            id: 'SWIFT-5.3A',
            framework: 'swift_csp',
            title: 'Personnel Vetting Process (Advisory)',
            description: 'Ensure trustworthiness of staff operating the SWIFT environment. '
                . 'Personnel vetting is an organizational process outside software scope.',
            status: ControlStatus::NotApplicable,
            frameworkFeatures: ['personnel_vetting'],
        ));

        $catalog->register(new Control(
            id: 'SWIFT-5.4',
            framework: 'swift_csp',
            title: 'Physical and Logical Password Storage (Mandatory)',
            description: 'Protect physically and logically recorded passwords. '
                . 'Credential encryption, hashing, and secure storage.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['password_hashing', 'credential_encryption', 'secure_storage'],
        ));

        // === 6. Detect Anomalous Activity to Systems or Transaction Records ===

        $catalog->register(new Control(
            id: 'SWIFT-6.1',
            framework: 'swift_csp',
            title: 'Malware Protection (Mandatory)',
            description: 'Ensure that the local SWIFT infrastructure is protected against '
                . 'malware. Map to input validation, file upload scanning, CSP headers.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['input_validation', 'file_upload_security', 'content_security_policy'],
        ));

        $catalog->register(new Control(
            id: 'SWIFT-6.2',
            framework: 'swift_csp',
            title: 'Software Integrity (Mandatory)',
            description: 'Ensure the software integrity of SWIFT-related applications. '
                . 'Map to build verification, code signing, integrity checking.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['integrity_verification', 'code_signing', 'checksum_validation'],
        ));

        $catalog->register(new Control(
            id: 'SWIFT-6.3',
            framework: 'swift_csp',
            title: 'Database Integrity (Mandatory)',
            description: 'Ensure the integrity of the database records for the SWIFT '
                . 'messaging interface and connected back-office applications.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['database_integrity', 'transaction_logging', 'audit_trail'],
        ));

        $catalog->register(new Control(
            id: 'SWIFT-6.4',
            framework: 'swift_csp',
            title: 'Logging and Monitoring (Mandatory)',
            description: 'Record security events and detect anomalous actions and operations '
                . 'within the local SWIFT environment. Map to AuditLogger and metrics.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['audit_logging', 'security_monitoring', 'event_correlation', 'metrics'],
        ));

        $catalog->register(new Control(
            id: 'SWIFT-6.5A',
            framework: 'swift_csp',
            title: 'Intrusion Detection (Advisory)',
            description: 'Detect and prevent anomalous network activity within the local '
                . 'SWIFT environment or suspicious behaviour from staff accounts.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['rate_limiting', 'anomaly_detection', 'suspicious_activity_alerts'],
        ));

        // === 7. Plan for Incident Response and Information Sharing ===

        $catalog->register(new Control(
            id: 'SWIFT-7.1',
            framework: 'swift_csp',
            title: 'Cyber Incident Response Planning (Mandatory)',
            description: 'Define and test a cyber incident response plan per SWIFT guidelines. '
                . 'Map to Pulsar IncidentReporter and incident management.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['incident_response', 'incident_reporter', 'response_playbooks'],
        ));

        $catalog->register(new Control(
            id: 'SWIFT-7.2',
            framework: 'swift_csp',
            title: 'Security Training and Awareness (Mandatory)',
            description: 'Ensure that all staff are aware of and fulfil their security '
                . 'responsibilities. Training is organizational, outside software scope.',
            status: ControlStatus::NotApplicable,
            frameworkFeatures: ['security_training'],
        ));

        $catalog->register(new Control(
            id: 'SWIFT-7.3A',
            framework: 'swift_csp',
            title: 'Penetration Testing (Advisory)',
            description: 'Validate the operational security configuration and identify '
                . 'security gaps by performing penetration testing.',
            status: ControlStatus::Partial,
            frameworkFeatures: ['penetration_testing', 'security_assessment', 'red_team'],
        ));

        $catalog->register(new Control(
            id: 'SWIFT-7.4A',
            framework: 'swift_csp',
            title: 'Scenario-Based Risk Assessment (Advisory)',
            description: 'Define cyber security defence and detection strategy through '
                . 'risk assessment methodology and scenario-based planning.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['risk_assessment', 'threat_modeling', 'scenario_planning'],
        ));
    }
}
