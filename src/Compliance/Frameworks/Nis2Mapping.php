<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Frameworks;

use Pulsar\Api\Internal;
use Pulsar\Compliance\Control;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Compliance\ControlStatus;

/**
 * Registers NIS2 Directive (EU 2022/2555) controls into the catalog.
 *
 * Maps Pulsar framework features to NIS2 cybersecurity risk management
 * measures under Article 21 and incident reporting under Article 23.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal(reason: 'Framework-internal control registration; use ControlCatalog for public access')]
final class Nis2Mapping
{
    /**
     * Register NIS2 controls into the given catalog.
     */
    public static function register(ControlCatalog $catalog): void
    {
        $catalog->register(new Control(
            id: 'NIS2-Art21(a)',
            framework: 'nis2',
            title: 'Risk Analysis and Information System Security Policies',
            description: 'Policies on risk analysis and information system security. Covered by the '
                . 'compliance control catalog, security configuration defaults, and deploy checks '
                . 'that verify security posture at deployment time.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['compliance_catalog', 'security_config', 'deploy_checks'],
        ));

        $catalog->register(new Control(
            id: 'NIS2-Art21(b)',
            framework: 'nis2',
            title: 'Incident Handling',
            description: 'Incident handling procedures. Covered by the IncidentReporter subsystem '
                . 'for structured incident reporting, audit logging with HMAC chains for '
                . 'tamper-evident event records, and compliance events.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['incident_reporter', 'audit_logging', 'hmac_chain', 'compliance_events'],
        ));

        $catalog->register(new Control(
            id: 'NIS2-Art21(d)',
            framework: 'nis2',
            title: 'Supply Chain Security',
            description: 'Supply chain security including security-related aspects concerning the '
                . 'relationships between each entity and its direct suppliers. Covered by '
                . 'ManifestSigner for integrity verification, composer.lock auditing support, '
                . 'and integrity hash verification of deployed artifacts.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['manifest_signer', 'integrity_verification', 'deploy_integrity_check'],
        ));

        $catalog->register(new Control(
            id: 'NIS2-Art21(e)',
            framework: 'nis2',
            title: 'Vulnerability Handling and Disclosure',
            description: 'Security in network and information systems acquisition, development, and '
                . 'maintenance, including vulnerability handling and disclosure. Covered by '
                . 'static analysis tooling (PHPStan, Psalm), deploy checks, and security headers. '
                . 'Vulnerability disclosure policy is an organizational responsibility.',
            status: ControlStatus::Partial,
            frameworkFeatures: ['static_analysis', 'deploy_checks', 'security_headers'],
        ));

        $catalog->register(new Control(
            id: 'NIS2-Art21(g)',
            framework: 'nis2',
            title: 'Basic Cyber Hygiene and Security Training',
            description: 'Basic cyber hygiene practices and cybersecurity training. Framework provides '
                . 'secure defaults (CSRF protection, security headers, session security, TLS enforcement) '
                . 'as building blocks. Training is an organizational responsibility.',
            status: ControlStatus::Partial,
            frameworkFeatures: ['csrf_protection', 'security_headers', 'session_security', 'tls_enforcement'],
        ));

        $catalog->register(new Control(
            id: 'NIS2-Art21(h)',
            framework: 'nis2',
            title: 'Cryptography and Encryption',
            description: 'Policies and procedures regarding the use of cryptography and, where appropriate, '
                . 'encryption. Covered by the pluggable cipher suite architecture (AES-256-GCM, '
                . 'XSalsa20-Poly1305), FIPS 140-2 compatible mode, and master key derivation framework.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['crypto_keyring', 'cipher_suite', 'fips_validator', 'master_key_derivation'],
        ));

        $catalog->register(new Control(
            id: 'NIS2-Art21(i)',
            framework: 'nis2',
            title: 'Human Resources Security and Access Control',
            description: 'Human resources security, access control policies, and asset management. '
                . 'Covered by the authentication subsystem (multi-factor, session management), '
                . 'role-based access control, and zero-trust verification.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['authentication', 'mfa', 'rbac', 'zero_trust', 'session_management'],
        ));

        $catalog->register(new Control(
            id: 'NIS2-Art23',
            framework: 'nis2',
            title: 'Incident Reporting Obligations',
            description: 'Significant incidents shall be reported to the competent authority. '
                . 'Covered by the IncidentReporter with structured severity classification, '
                . 'audit logging for evidence preservation, and compliance event emission.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['incident_reporter', 'audit_logging', 'compliance_events'],
        ));
    }
}
