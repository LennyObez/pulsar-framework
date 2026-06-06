<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Frameworks;

use Pulsar\Api\Internal;
use Pulsar\Compliance\Control;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Compliance\ControlStatus;

/**
 * Registers SOC 2 Trust Services Criteria controls into the catalog.
 *
 * Maps Pulsar framework features to the SOC 2 controls they provide coverage for.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal(reason: 'Framework-internal control registration; use ControlCatalog for public access')]
final class Soc2Mapping
{
    /**
     * Register SOC 2 controls into the given catalog.
     */
    public static function register(ControlCatalog $catalog): void
    {
        $catalog->register(new Control(
            id: 'CC1.1',
            framework: 'soc2',
            title: 'COSO Principle 1: Integrity and Ethical Values',
            description: 'The entity demonstrates a commitment to integrity and ethical values through '
                . 'comprehensive audit logging that captures all security-relevant operations with '
                . 'tamper-evident chains.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['audit_logging', 'hmac_chain'],
        ));

        $catalog->register(new Control(
            id: 'CC6.1',
            framework: 'soc2',
            title: 'Logical and Physical Access Controls',
            description: 'The entity implements logical access security software, infrastructure, and '
                . 'architectures over protected information assets to protect them from security events. '
                . 'Covered by authentication middleware, session management, and authorization policies.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['authentication', 'authorization', 'session_management'],
        ));

        $catalog->register(new Control(
            id: 'CC6.3',
            framework: 'soc2',
            title: 'Role-Based Access Control',
            description: 'The entity authorizes, modifies, or removes access to data, software, functions, '
                . 'and other protected information assets based on roles and responsibilities. '
                . 'Covered by the RBAC subsystem and permission gates.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['rbac', 'permission_gates', 'authorization'],
        ));

        $catalog->register(new Control(
            id: 'CC7.2',
            framework: 'soc2',
            title: 'System Monitoring',
            description: 'The entity monitors system components and the operation of those components for '
                . 'anomalies that are indicative of malicious acts, natural disasters, and errors affecting '
                . 'the entity\'s ability to meet its objectives. Covered by metrics collection, health '
                . 'checks, and observability instrumentation.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['observability', 'metrics', 'health_checks'],
        ));

        $catalog->register(new Control(
            id: 'CC8.1',
            framework: 'soc2',
            title: 'Change Management',
            description: 'The entity authorizes, designs, develops or acquires, configures, documents, tests, '
                . 'approves, and implements changes to infrastructure, data, software, and procedures to '
                . 'meet its objectives. Covered by deployment pipelines and integrity verification.',
            status: ControlStatus::Partial,
            frameworkFeatures: ['deployment', 'integrity_verification', 'extension_signatures'],
        ));

        // ── CC1: Control Environment ────────────────────────────────────

        $catalog->register(new Control(
            id: 'CC1.2',
            framework: 'soc2',
            title: 'Board of Directors Independence and Oversight',
            description: 'Framework enforces separation of duties via RBAC with distinct admin, '
                . 'developer, and auditor roles that cannot be combined.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['rbac', 'role_separation', 'authorization'],
        ));

        $catalog->register(new Control(
            id: 'CC1.3',
            framework: 'soc2',
            title: 'Management Responsibility for Internal Controls',
            description: 'Compliance verification engine provides automated control checking '
                . 'with regression detection and evidence chain tracking.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['compliance_verification', 'regression_detection', 'evidence_chain'],
        ));

        $catalog->register(new Control(
            id: 'CC1.4',
            framework: 'soc2',
            title: 'Competence of Personnel',
            description: 'Extension trust tiers enforce capability restrictions based on '
                . 'author verification level (community, verified, first-party).',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['trust_tiers', 'capability_policy', 'extension_signatures'],
        ));

        $catalog->register(new Control(
            id: 'CC1.5',
            framework: 'soc2',
            title: 'Accountability for Internal Controls',
            description: 'Audit log with HMAC chain provides tamper-evident record of all '
                . 'security-relevant operations with actor attribution.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['audit_logging', 'hmac_chain', 'actor_attribution'],
        ));

        // ── CC2: Communication and Information ──────────────────────────

        $catalog->register(new Control(
            id: 'CC2.1',
            framework: 'soc2',
            title: 'Internal Information Quality',
            description: 'Structured logging with compliance formatters (HIPAA, PCI-DSS, SOX) '
                . 'ensures information quality and consistency.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['structured_logging', 'compliance_log_formatters'],
        ));

        $catalog->register(new Control(
            id: 'CC2.2',
            framework: 'soc2',
            title: 'Internal Communication',
            description: 'Event dispatcher with cross-module scope tracking enables auditable '
                . 'internal communication between framework components.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['event_dispatcher', 'cross_module_events'],
        ));

        $catalog->register(new Control(
            id: 'CC2.3',
            framework: 'soc2',
            title: 'External Communication',
            description: 'Notification channels (email, webhook, SMS) with audit trails for '
                . 'all external communications including breach notifications.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['notifications', 'webhook_channel', 'breach_notification'],
        ));

        // ── CC3: Risk Assessment ────────────────────────────────────────

        $catalog->register(new Control(
            id: 'CC3.1',
            framework: 'soc2',
            title: 'Risk Identification',
            description: 'Account takeover guard with risk scoring identifies authentication '
                . 'threats. Rate limiters detect brute force and abuse patterns.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['takeover_guard', 'rate_limiting', 'risk_scoring'],
        ));

        $catalog->register(new Control(
            id: 'CC3.2',
            framework: 'soc2',
            title: 'Risk Assessment for Fraud',
            description: 'CSRF middleware, input validation, and sanitization prevent injection '
                . 'and cross-site attacks. SVG sanitizer blocks malicious uploads.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['csrf_protection', 'input_validation', 'svg_sanitizer'],
        ));

        $catalog->register(new Control(
            id: 'CC3.3',
            framework: 'soc2',
            title: 'Risk Assessment for Changes',
            description: 'Build artifact verification with integrity checks ensures code '
                . 'changes are authorized. Extension signatures verify provenance.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['build_verification', 'integrity_checks', 'extension_signatures'],
        ));

        $catalog->register(new Control(
            id: 'CC3.4',
            framework: 'soc2',
            title: 'Consideration of External Threats',
            description: 'Security headers middleware (CSP, HSTS, X-Frame-Options), CORS '
                . 'policy enforcement, and safe redirect validation.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['security_headers', 'cors', 'safe_redirect'],
        ));

        // ── CC4: Monitoring Activities ──────────────────────────────────

        $catalog->register(new Control(
            id: 'CC4.1',
            framework: 'soc2',
            title: 'Ongoing Monitoring',
            description: 'MetricRegistry with threshold-based alerting provides continuous '
                . 'monitoring. Health check endpoints expose component status.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['metrics', 'alerting', 'health_checks'],
        ));

        $catalog->register(new Control(
            id: 'CC4.2',
            framework: 'soc2',
            title: 'Evaluation and Communication of Deficiencies',
            description: 'Error tracking with aggregation and fingerprinting identifies and '
                . 'groups deficiencies. Compliance verification reports gaps.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['error_tracking', 'error_aggregation', 'compliance_verification'],
        ));

        // ── CC5: Control Activities ─────────────────────────────────────

        $catalog->register(new Control(
            id: 'CC5.1',
            framework: 'soc2',
            title: 'Selection and Development of Controls',
            description: 'Framework provides defense-in-depth with authentication, authorization, '
                . 'encryption, audit logging, and input validation as composable middleware.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['authentication', 'authorization', 'encryption', 'middleware_pipeline'],
        ));

        $catalog->register(new Control(
            id: 'CC5.2',
            framework: 'soc2',
            title: 'Technology Controls',
            description: 'Automated deployment gates, CI verification, and runtime integrity '
                . 'checks ensure technology controls are consistently applied.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['deployment_gates', 'ci_verification', 'runtime_integrity'],
        ));

        $catalog->register(new Control(
            id: 'CC5.3',
            framework: 'soc2',
            title: 'Deployment of Control Activities',
            description: 'Middleware pipeline enforces security controls (auth, rate limit, '
                . 'CSRF, headers) uniformly across all HTTP endpoints.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['middleware_pipeline', 'security_middleware'],
        ));

        // ── CC6: Additional Logical and Physical Access Controls ────────

        $catalog->register(new Control(
            id: 'CC6.2',
            framework: 'soc2',
            title: 'Prior to Issuing System Credentials',
            description: 'Password hashing (Argon2id via PasswordHasher), TOTP 2FA enrollment, '
                . 'and recovery code generation for identity verification before credential issuance.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['password_hashing', 'totp_2fa', 'recovery_codes'],
        ));

        $catalog->register(new Control(
            id: 'CC6.4',
            framework: 'soc2',
            title: 'Restriction of Physical Access',
            description: 'Not directly applicable: framework is software-only. IP allowlisting '
                . 'and maintenance mode provide network-level access restrictions.',
            status: ControlStatus::Partial,
            frameworkFeatures: ['ip_allowlisting', 'maintenance_mode'],
        ));

        $catalog->register(new Control(
            id: 'CC6.5',
            framework: 'soc2',
            title: 'Disposal of Confidential Information',
            description: 'Data purge orchestrator with retention policies enables automated '
                . 'disposal. Sodium memory zeroing for cryptographic keys.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['data_purge', 'retention_policies', 'memory_zeroing'],
        ));

        $catalog->register(new Control(
            id: 'CC6.6',
            framework: 'soc2',
            title: 'Logical Access: External Threats',
            description: 'Rate limiting, account lockout, and step-up authentication protect '
                . 'against brute force, credential stuffing, and privilege escalation.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['rate_limiting', 'step_up_auth', 'takeover_guard'],
        ));

        $catalog->register(new Control(
            id: 'CC6.7',
            framework: 'soc2',
            title: 'Transmission Integrity',
            description: 'HSTS enforcement, TLS requirement for JWKS/OAuth endpoints, and '
                . 'signed build artifacts ensure data integrity in transit.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['hsts', 'tls_enforcement', 'signed_artifacts'],
        ));

        $catalog->register(new Control(
            id: 'CC6.8',
            framework: 'soc2',
            title: 'Preventing Unauthorized Software',
            description: 'Extension trust tiers with Ed25519 signature verification prevent '
                . 'unauthorized code execution. Capability policies restrict API access.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['trust_tiers', 'signature_verification', 'capability_policy'],
        ));

        // ── CC7: System Operations ──────────────────────────────────────

        $catalog->register(new Control(
            id: 'CC7.1',
            framework: 'soc2',
            title: 'Anomaly Detection',
            description: 'Error tracking with fingerprinting detects anomalous patterns. '
                . 'Metric threshold alerting identifies operational anomalies.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['error_tracking', 'threshold_alerting', 'anomaly_detection'],
        ));

        $catalog->register(new Control(
            id: 'CC7.3',
            framework: 'soc2',
            title: 'Security Incident Response',
            description: 'Breach notification service, incident logging, and compliance-aware '
                . 'audit formatters support structured incident response.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['breach_notification', 'incident_logging', 'audit_logging'],
        ));

        $catalog->register(new Control(
            id: 'CC7.4',
            framework: 'soc2',
            title: 'Recovery from Security Incidents',
            description: 'Key rotation, session invalidation, and password reset flows '
                . 'support recovery. Backup/restore capabilities for data recovery.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['key_rotation', 'session_management', 'backup_restore'],
        ));

        $catalog->register(new Control(
            id: 'CC7.5',
            framework: 'soc2',
            title: 'Identification of Vulnerabilities',
            description: 'Static analysis integration (PHPStan, Psalm), boundary enforcement, '
                . 'and dependency auditing identify code vulnerabilities.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['static_analysis', 'boundary_enforcement', 'dependency_audit'],
        ));

        // ── CC9: Risk Mitigation ────────────────────────────────────────

        $catalog->register(new Control(
            id: 'CC9.1',
            framework: 'soc2',
            title: 'Risk Mitigation through Controls',
            description: 'Circuit breaker and resilience patterns prevent cascading failures. '
                . 'Queue health monitoring with dead letter handling.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['circuit_breaker', 'resilience', 'queue_health'],
        ));

        $catalog->register(new Control(
            id: 'CC9.2',
            framework: 'soc2',
            title: 'Vendor Risk Management',
            description: 'Extension trust tiers categorize third-party code risk. Scoped '
                . 'container proxies isolate vendor code from framework internals.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['trust_tiers', 'scoped_containers', 'vendor_isolation'],
        ));

        // ── A1: Availability ────────────────────────────────────────────

        $catalog->register(new Control(
            id: 'A1.1',
            framework: 'soc2',
            title: 'Availability Commitments and Objectives',
            description: 'Performance budgets, health check endpoints, and boot profiling '
                . 'support availability measurement and SLA compliance.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['performance_budgets', 'health_checks', 'boot_profiling'],
        ));

        $catalog->register(new Control(
            id: 'A1.2',
            framework: 'soc2',
            title: 'Environmental Protections',
            description: 'Database failover manager, connection pooling, and persistent '
                . 'runtimes support high-availability deployments.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['failover_manager', 'connection_pooling', 'persistent_runtime'],
        ));

        $catalog->register(new Control(
            id: 'A1.3',
            framework: 'soc2',
            title: 'Recovery Procedures',
            description: 'Backup service with integrity verification (BLAKE2b hashing) and '
                . 'restore capabilities. Database migration rollback support.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['backup_service', 'integrity_hashing', 'migration_rollback'],
        ));

        // ── PI1: Processing Integrity ───────────────────────────────────

        $catalog->register(new Control(
            id: 'PI1.1',
            framework: 'soc2',
            title: 'Processing Integrity Policies',
            description: 'Input validation, CSRF protection, and content type enforcement '
                . 'ensure processing integrity for all HTTP operations.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['input_validation', 'csrf_protection', 'content_type_enforcement'],
        ));

        $catalog->register(new Control(
            id: 'PI1.2',
            framework: 'soc2',
            title: 'Accuracy and Completeness',
            description: 'Saga orchestrator with step-level tracking ensures multi-step '
                . 'operations complete fully or compensate correctly.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['saga_orchestrator', 'compensation', 'step_tracking'],
        ));

        // ── C1: Confidentiality ─────────────────────────────────────────

        $catalog->register(new Control(
            id: 'C1.1',
            framework: 'soc2',
            title: 'Confidential Information Identification',
            description: 'Sensitive data scrubber for error tracking, redaction pipeline for '
                . 'logs, and encrypted column guard for ORM fields.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['data_scrubber', 'redaction_pipeline', 'encrypted_columns'],
        ));

        $catalog->register(new Control(
            id: 'C1.2',
            framework: 'soc2',
            title: 'Confidential Information Disposal',
            description: 'Data purge orchestrator with configurable retention policies per '
                . 'data category. Memory zeroing for cryptographic material.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['data_purge', 'retention_policies', 'memory_zeroing'],
        ));

        // ── P1: Privacy ─────────────────────────────────────────────────

        $catalog->register(new Control(
            id: 'P1.1',
            framework: 'soc2',
            title: 'Privacy Notice',
            description: 'Cookie consent banner with granular opt-in for GDPR compliance. '
                . 'Consent manager tracks and audits all consent decisions.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['consent_banner', 'consent_manager', 'gdpr_compliance'],
        ));

        $catalog->register(new Control(
            id: 'P1.2',
            framework: 'soc2',
            title: 'Choice and Consent',
            description: 'ConsentManagerInterface with per-purpose tracking, policy versioning, '
                . 'and revocation support. Granular cookie consent categories.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['consent_manager', 'purpose_tracking', 'consent_revocation'],
        ));
    }
}
