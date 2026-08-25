<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Control;

use Pulsar\Api\Api;

/**
 * Every fact a probe may reason about.
 *
 * The vocabulary is closed and total: {@see \Pulsar\Compliance\Evidence\ControlEvidenceGatherer}
 * produces one {@see Observation} per case on every run, so a probe can neither
 * name a fact nobody gathers (no such enum case exists) nor receive null and
 * read the absence of a measurement as a pass.
 *
 * One invariant holds for every case, and probes depend on it: an observation's
 * `present` flag is true when the DESIRABLE state was found. Facts whose plain
 * English reads negatively are therefore spelled positively here — the wiring
 * inspector's inert-feature list is `SecurityFeaturesIntact`, not "inert
 * features", so that `!$observation->present` always means "this is a gap" and
 * {@see Observation::isAdmissibleAsProof()} is meaningful for every case rather
 * than for most of them.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
enum ObservationId: string
{
    // --- Resolved implementation identity -----------------------------------
    // Which concrete class answered an interface, never merely that something
    // is bound. `present` means the class that answered is one the probe family
    // accepts as doing the work (e.g. a durable token store, not an in-memory one).

    case TokenVaultPersistence = 'token_vault_persistence';
    case AuditSinkResolved = 'audit_sink_resolved';
    case EvidenceStoreResolved = 'evidence_store_resolved';
    case SessionEncryptionResolved = 'session_encryption_resolved';
    case MasterKeyResolved = 'master_key_resolved';
    case MfaSubsystemResolved = 'mfa_subsystem_resolved';
    case IncidentReporterResolved = 'incident_reporter_resolved';
    case BackupPrimitiveResolved = 'backup_primitive_resolved';
    case ConsentSubsystemResolved = 'consent_subsystem_resolved';
    case ErasureSubsystemResolved = 'erasure_subsystem_resolved';
    case SubjectRequestHandlerResolved = 'subject_request_handler_resolved';
    case RetentionScheduleResolved = 'retention_schedule_resolved';
    case PseudonymizationResolved = 'pseudonymization_resolved';
    case ObservabilityExporterResolved = 'observability_exporter_resolved';
    case AuthRateLimiterEnforcing = 'auth_rate_limiter_enforcing';
    case ComplianceProfileResolved = 'compliance_profile_resolved';
    case SecurityFeaturesIntact = 'security_features_intact';
    case ClassifiedRouteCoverage = 'classified_route_coverage';

    // --- Resolved identity, AI governance extension --------------------------

    case AiGovernanceExtensionActive = 'ai_governance_extension_active';
    case AiModelRegistryResolved = 'ai_model_registry_resolved';
    case AiImpactAssessmentResolved = 'ai_impact_assessment_resolved';
    case AiAuditLoggerResolved = 'ai_audit_logger_resolved';
    case AiDataGovernanceResolved = 'ai_data_governance_resolved';
    case AiExplainabilityResolved = 'ai_explainability_resolved';
    case AiLifecycleManagerResolved = 'ai_lifecycle_manager_resolved';
    case AiDeploymentGateResolved = 'ai_deployment_gate_resolved';
    case AiMonitoringHookResolved = 'ai_monitoring_hook_resolved';
    case AiTransparencyResolved = 'ai_transparency_resolved';

    // --- Measured behaviour --------------------------------------------------
    // Something ran: an algorithm, an HMAC recomputation, a health check, a query.
    //
    // TokenVaultRendersUnreadable is the only fact in the vocabulary whose
    // measurement WRITES. It has to: ADR-0041 closed "the class exists" and left
    // "the class is bound", and this repository proves the gap is not theoretical
    // — TokenStoreInterface resolves to DatabaseTokenStore against a database
    // holding no `token_vault` table, so the vault throws on its first use while
    // the binding reads clean. Only exercising the vault distinguishes the two.
    // See {@see \Pulsar\Compliance\Evidence\TokenVaultObserver} for what it
    // writes, under which context, and how it is removed again.

    case CryptographicCapability = 'cryptographic_capability';
    case FipsValidatedCryptography = 'fips_validated_cryptography';
    case MasterKeyMaterial = 'master_key_material';
    case KeyDerivationVerified = 'key_derivation_verified';
    case AuditChainVerified = 'audit_chain_verified';
    case HealthChecksExecuted = 'health_checks_executed';
    case DatabaseTransportEncrypted = 'database_transport_encrypted';
    case TokenVaultRendersUnreadable = 'token_vault_renders_unreadable';

    // --- Declared configuration ---------------------------------------------
    // Read from config. Records what was requested, never what happened, and so
    // can never on its own carry a control to Satisfied.

    case CsrfProtectionActive = 'csrf_protection_active';
    case TransportSecurityEnforced = 'transport_security_enforced';
    case SessionEncryptionConfigured = 'session_encryption_configured';
    case SessionCookiesHardened = 'session_cookies_hardened';
    case SecurityHeadersConfigured = 'security_headers_configured';
    case DebugModeDisabled = 'debug_mode_disabled';
    case RetentionBounded = 'retention_bounded';
    case BreachNotificationDeadlineSet = 'breach_notification_deadline_set';
    case PasswordPolicyEnforced = 'password_policy_enforced';
    case MfaPolicyRequired = 'mfa_policy_required';

    // --- Operator scope assertions ------------------------------------------
    // Nothing in the tree records which data classes a deployment handles and no
    // code can derive it. Admissible for exactly one thing: marking a control
    // NotApplicable, reproduced in the report with the config key that carried it.
    //
    // Each of the three is a claim about the DATA the deployment handles, and each
    // is read by at least one probe. There is deliberately no assertion that a
    // whole standard does not apply: enabling a framework in config/compliance.php
    // IS the claim that the deployment must satisfy it, so the way to stop being
    // assessed against one is to stop enabling it, in a diff, rather than to
    // silence it with a second config key.

    case ScopeStoresCardholderData = 'scope_stores_cardholder_data';
    case ScopeProcessesHealthData = 'scope_processes_health_data';
    case ScopeProcessesPersonalData = 'scope_processes_personal_data';
}
