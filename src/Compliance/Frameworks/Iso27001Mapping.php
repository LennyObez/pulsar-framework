<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Frameworks;

use Pulsar\Api\Internal;
use Pulsar\Compliance\Control;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Compliance\ControlStatus;

/**
 * Registers ISO 27001:2022 Annex A controls into the catalog.
 *
 * Maps Pulsar framework features to ISO 27001:2022 Annex A technological
 * controls (A.8.x). Organizational (A.5.x), people (A.6.x), and physical
 * (A.7.x) controls are documented as organizational responsibilities.
 */
#[Internal(reason: 'Framework-internal control registration; use ControlCatalog for public access')]
final class Iso27001Mapping
{
    /**
     * Register ISO 27001:2022 controls into the given catalog.
     */
    public static function register(ControlCatalog $catalog): void
    {
        // --- A.5.x Organizational Controls (framework-relevant subset) ---

        $catalog->register(new Control(
            id: 'A.5.1',
            framework: 'iso27001',
            title: 'Policies for Information Security',
            description: 'Information security policy and topic-specific policies shall be defined, '
                . 'approved by management, published, communicated, and reviewed. Framework provides '
                . 'configurable security policies (CSP, CORS, rate limiting, session) as code. '
                . 'Organizational policy documents are the deployer\'s responsibility.',
            status: ControlStatus::Partial,
            frameworkFeatures: ['security_config', 'csp_headers', 'cors_config', 'rate_limiting'],
        ));

        // --- A.8.x Technological Controls ---

        $catalog->register(new Control(
            id: 'A.8.1',
            framework: 'iso27001',
            title: 'User Endpoint Devices',
            description: 'Information stored on, processed by, or accessible via user endpoint devices '
                . 'shall be protected. Framework enforces secure session management with idle timeouts, '
                . 'secure cookie attributes (HttpOnly, Secure, SameSite), and session validators '
                . '(user agent, fingerprint, remote address).',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['session_management', 'cookie_security', 'session_validators', 'idle_timeout'],
        ));

        $catalog->register(new Control(
            id: 'A.8.3',
            framework: 'iso27001',
            title: 'Information Access Restriction',
            description: 'Access to information and other associated assets shall be restricted in '
                . 'accordance with the established topic-specific policy on access control. '
                . 'Covered by role-based access control, middleware guards, and zero-trust verification.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['rbac', 'authentication', 'middleware_guards', 'zero_trust'],
        ));

        $catalog->register(new Control(
            id: 'A.8.5',
            framework: 'iso27001',
            title: 'Secure Authentication',
            description: 'Secure authentication technologies and procedures shall be established. '
                . 'Covered by multi-factor authentication, session management with configurable '
                . 'validators, CSRF protection, and password hashing.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['authentication', 'mfa', 'csrf_protection', 'session_management'],
        ));

        $catalog->register(new Control(
            id: 'A.8.9',
            framework: 'iso27001',
            title: 'Configuration Management',
            description: 'Configurations, including security configurations, of hardware, software, '
                . 'services, and networks shall be established, documented, implemented, monitored, '
                . 'and reviewed. Covered by typed configuration DTOs, deploy checks that verify '
                . 'production readiness, and health check infrastructure.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['config_management', 'deploy_checks', 'health_checks'],
        ));

        $catalog->register(new Control(
            id: 'A.8.12',
            framework: 'iso27001',
            title: 'Data Leakage Prevention',
            description: 'Data leakage prevention measures shall be applied to systems, networks, and '
                . 'any other devices that process, store, or transmit sensitive information. '
                . 'Covered by tokenization service (PAN protection), pseudonymization, output escaping, '
                . 'and security headers (CSP, X-Content-Type-Options).',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['tokenization', 'pseudonymization', 'output_escaping', 'security_headers'],
        ));

        $catalog->register(new Control(
            id: 'A.8.15',
            framework: 'iso27001',
            title: 'Logging',
            description: 'Logs that record activities, exceptions, faults, and other relevant events '
                . 'shall be produced, stored, protected, and analysed. Covered by structured audit '
                . 'logging with HMAC chain tamper detection, compliance event emission, and '
                . 'observability integration (OpenTelemetry, Prometheus).',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['audit_logging', 'hmac_chain', 'compliance_events', 'observability'],
        ));

        $catalog->register(new Control(
            id: 'A.8.24',
            framework: 'iso27001',
            title: 'Use of Cryptography',
            description: 'Rules for the effective use of cryptography, including cryptographic key '
                . 'management, shall be defined and implemented. Covered by the pluggable cipher '
                . 'suite architecture, master key derivation with domain separation, key rotation '
                . 'support, and FIPS 140-2 compatible mode.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['crypto_keyring', 'cipher_suite', 'key_rotation', 'fips_validator'],
        ));

        $catalog->register(new Control(
            id: 'A.8.25',
            framework: 'iso27001',
            title: 'Secure Development Life Cycle',
            description: 'Rules for the secure development of software and systems shall be established '
                . 'and applied. Covered by static analysis (PHPStan level max, Psalm level 1), '
                . 'boundary enforcement (Deptrac), API surface tracking, and comprehensive test suites.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['static_analysis', 'boundary_enforcement', 'api_snapshot', 'test_suite'],
        ));

        $catalog->register(new Control(
            id: 'A.8.26',
            framework: 'iso27001',
            title: 'Application Security Requirements',
            description: 'Information security requirements shall be identified, specified, and approved '
                . 'when developing or acquiring applications. Covered by input validation, CSRF '
                . 'protection, request normalization, body size limits, and SSRF prevention.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['input_validation', 'csrf_protection', 'request_normalization', 'ssrf_prevention'],
        ));
    }
}
