<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Control;

use NoDiscard;
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
 *
 * A SECOND INVARIANT ARRIVED WITH {@see ControlSubject}: every case names the
 * ESTATE it interrogated, through {@see subject()}, and the grouping comments
 * below describe how a fact was obtained rather than what it is about. Read the
 * two together, because they are independent and each was defeated on its own.
 * `runtime.sodium_extension` was wrong in the first sense — a loaded extension is
 * not a measurement (ADR-0061). `audit_chain_verified` is right in the first
 * sense and wrong in the second: it is a genuine cryptographic recomputation, of
 * the compliance evidence register rather than of the audit trail that twelve
 * controls requiring it regulate (ADR-0062).
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
    // The table the mappings rest in, kept apart from the service that writes
    // them for the reason TokenVaultPersistence is kept apart from the vault: a
    // pseudonymisation service standing on InMemoryPseudonymLookup mints, resolves
    // and erases perfectly inside one process and has forgotten every subject by
    // the next one — so it can neither answer an Art 15 request about a
    // pseudonymised record nor perform the Art 17 erasure, because there is
    // nothing left to erase. The measurement beside it cannot see that: it runs in
    // the process that would lose the table.
    case PseudonymTablePersistence = 'pseudonym_table_persistence';
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

    // --- The cryptography a deployment could use, and the one it bound --------
    // Neither of these is behaviour, and both used to be filed as if they were.
    // They are kept together and apart from the block below so that the boundary
    // between "the platform has it" and "this deployment ran it" is visible in the
    // vocabulary rather than only in a gatherer three files away.
    //
    // CryptographicCapability is graded {@see ObservationGrade::Available}: the
    // check behind it is `extension_loaded('sodium')` plus one function_exists,
    // which answers the same on a deployment that encrypts everything and on one
    // that encrypts nothing.
    //
    // FipsValidatedCryptography is graded {@see ObservationGrade::Resolved}, not
    // Available, and the difference is the point of separating them: its
    // discriminating input is WHICH CipherSuite this deployment bound, judged
    // against an approved list. That is a resolution. The platform introspection
    // beside it — the OpenSSL provider, the version banner — can only ever
    // downgrade the answer, never produce one.

    case CryptographicCapability = 'cryptographic_capability';
    case FipsValidatedCryptography = 'fips_validated_cryptography';

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
    //
    // AiTransparencyExercised is the SECOND that writes, and it cannot undo what
    // it wrote: `AiTransparencyInterface` has no withdrawal, and growing a shipped
    // contract so a compliance check could tidy up after itself is not a trade
    // worth making. Its residue is bounded structurally instead — declarations are
    // keyed by surface, so everything goes under one reserved id and a later run
    // replaces it. {@see \Pulsar\Compliance\Evidence\AiTransparencyObserver}
    // establishes that bound by declaring the id twice and reading the store back.
    //
    // TWO MORE WRITE, and they exist because ADR-0046 left GDPR Art 25 and Art 33
    // failing on a default install with nothing in the tree that could ever close
    // them honestly. Each pays for its write differently:
    //
    //   IdentifierPseudonymizedAndErased writes a mapping and then ERASES it, and
    //   the erasure is not cleanup borrowed from somewhere else — it IS the
    //   control. Art 17 is what ForgetService exists to perform, so the step that
    //   removes what this check wrote is the same step that evidences the
    //   requirement. What is left behind is a table holding no mapping this check
    //   created.
    //
    //   IncidentRecordedAndRetained cannot take back what it wrote:
    //   IncidentReporterInterface has no removal, deliberately, because a register
    //   whose entries can be deleted evidences nothing. So this one leaves one
    //   Low-severity row per report run. See
    //   {@see \Pulsar\Compliance\Evidence\IncidentRegisterObserver} for why that is
    //   the right trade and what the row says about itself.

    // TWO MORE WRITE, and they are what took the ISO 42001 mapping off resolutions.
    // Thirteen of its controls rested on which class answered an AI governance
    // contract, and the four the extension shipped were in-memory stores; the
    // report knew that only from a hard-coded list of class names in the gatherer,
    // which grades a store by recognising it. These two grade a store by what it
    // did:
    //
    //   AiGovernanceRecordsDurable writes a model, an assessment, a data quality
    //   report and an explanation, and reads each back through a SECOND store
    //   instance that shares no memory with the one that wrote. A record held in a
    //   PHP array is not on that path. Its residue is bounded the way the
    //   transparency declaration's is — every write goes under one reserved
    //   identifier, each store is keyed by the id its own DTO documents as unique,
    //   and the check establishes the bound by writing twice and reading the
    //   inventory size. See {@see \Pulsar\Compliance\Evidence\AiGovernanceRecordObserver}.
    //
    //   AiMonitoringExercised is the only fact here that takes its write back in
    //   full. It runs the registered monitoring hooks and requires the results to
    //   have been retained — Clause 9.1's closing sentence — and then disposes of
    //   the records it wrote through the store's own retention path, because a
    //   monitoring record is an EVENT and a keyed replacement would be the wrong
    //   shape for it. It is the fact that replaced `ai_monitoring_hook_resolved`
    //   as the carrier of Clause 9.1, which is the control that was Implemented on
    //   the strength of an interface with no implementations anywhere in the tree.
    //   See {@see \Pulsar\Compliance\Evidence\AiMonitoringObserver}.

    case KeyDerivationVerified = 'key_derivation_verified';
    case AuditChainVerified = 'audit_chain_verified';
    case HealthChecksExecuted = 'health_checks_executed';
    case DatabaseTransportEncrypted = 'database_transport_encrypted';
    case TokenVaultRendersUnreadable = 'token_vault_renders_unreadable';
    case AiTransparencyExercised = 'ai_transparency_exercised';
    case AiGovernanceRecordsDurable = 'ai_governance_records_durable';
    case AiMonitoringExercised = 'ai_monitoring_exercised';
    case IdentifierPseudonymizedAndErased = 'identifier_pseudonymized_and_erased';
    case IncidentRecordedAndRetained = 'incident_recorded_and_retained';

    // AND ONE MORE THAT WRITES, and it is the fact that closed the only control
    // in the catalog that had NO carrier at all. `backup_primitive_resolved` above
    // asks which class answers a backup contract, and until this release nothing
    // in `src/` answered it — NIST CSF RC.RP, SOC 2 A1.3 and HIPAA 164.308(a)(7)
    // reported a primitive the framework did not have. Building the primitive
    // would not by itself have closed them: a bound BackupServiceInterface is a
    // resolution, and a resolution proves nothing (ADR-0041).
    //
    // What this fact records is a round trip. {@see \Pulsar\Compliance\Evidence\BackupRoundTripObserver}
    // seals a synthetic payload into a real archive with the deployment's real
    // archive key on the deployment's real backup destination, reads it back,
    // restores it into memory and compares it byte for byte, offers the same
    // archive with one byte changed and requires the refusal, and then removes
    // the archive it wrote. A deployment whose backup destination is unwritable,
    // whose key hierarchy will not derive, or whose service is bound to something
    // that does not actually seal, fails it — and each of those reads clean under
    // every binding inspection in the vocabulary.
    //
    // Its residue is bounded and it is the only writing fact here that can remove
    // ALL of its own: the archive is a file this check created under a reserved
    // name, and unlinking it is not borrowed cleanup but the correct end state,
    // since a synthetic archive left in an operator's backup directory would be
    // indistinguishable from a real one during a recovery.
    //
    // WHAT IT DOES NOT ESTABLISH, because the boundary matters more here than
    // anywhere else in this vocabulary: it does not restore the deployment's own
    // data, and it must never try. A probe that restored the live database would
    // be the disaster it is rehearsing for. So the fact is about the recovery
    // MECHANISM — sealing, verification, refusal, restoration — and what the
    // mechanism carries on a given night is the manifest of an actual
    // `pulsar backup:run`, which is an operator artefact and says so.

    case BackupRoundTripVerified = 'backup_round_trip_verified';

    // AND ONE THAT WRITES NOTHING AT ALL. SessionPayloadsSealed had to be BUILT
    // rather than regraded: ADR-0061 took `extension_loaded('sodium')` down to
    // Available and left nine controls resting on nothing, while the fact that
    // was supposed to answer for session payloads — SessionEncryptionConfigured,
    // below — is a config read, and the cipher could not be exercised from
    // Compliance at all because it is #[Internal] to the Security module. It
    // answers a published contract now, and
    // {@see \Pulsar\Compliance\Evidence\SessionSealObserver} seals a payload,
    // opens it, modifies one byte and offers it as another session. The cipher
    // RETURNS the at-rest form, so unlike the four writing facts above, the bytes
    // a handler would store are inspectable without anything being stored.

    case SessionPayloadsSealed = 'session_payloads_sealed';

    // AND A SECOND THAT WRITES NOTHING, for the estate SessionPayloadsSealed
    // deliberately does not reach. That fact's own docblock ends by saying the
    // five controls about personal, confidential and health data "still cannot
    // reach Satisfied on any deployment this release can build. Measuring their
    // estates is the next observer, not this one." This is that observer, for one
    // of those estates.
    //
    // WHAT MAKES IT A FACT ABOUT PERSONAL DATA rather than about a cipher. The
    // framework owns no store of the application's personal data and can never
    // observe one, so a payload put through the bound encryptor and back would be
    // a fact about {@see ControlSubject::KeyHierarchy} — the default encryption
    // subkey — dressed up. What the framework DOES own is the rule that decides
    // when a value gets encrypted at rest: `ClassifiedContext::requiresEncryption()`
    // seals a field classified {@see \Pulsar\Workflow\Storage\ClassificationLevel::Pii}
    // and leaves the rest in the clear, and `DatabaseWorkflowStorage` writes the
    // result. So the exercise is not "encrypt something" but "tell this deployment
    // a value is personal data and watch what it stores" — the classification is
    // the input and the deployment's response is the measurement.

    case PersonalDataFieldSealed = 'personal_data_field_sealed';

    // --- Declared configuration ---------------------------------------------
    // Read from config. Records what was requested, never what happened, and so
    // can never on its own carry a control to Satisfied.

    // MasterKeyMaterial leads the block because it is the one that moved into it.
    // SecurityPostureCheck reads PULSAR_MASTER_KEY from the environment, checks
    // that it parses, and reads two booleans saying whether the wiring bound a
    // MasterKey and an encryptor. Reading a value from the environment rather than
    // from a file does not make it something that ran, and binding presence is the
    // claim {@see ObservationGrade} deliberately has no case for at all.

    case MasterKeyMaterial = 'master_key_material';
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

    /**
     * The estate this fact interrogated.
     *
     * A property of the FACT'S IDENTITY, not of the observation instance and not
     * of whoever produced it. That is a deliberate strengthening of the design
     * this method comes from, which had the estate travelling as a caller-supplied
     * argument inside `src/Compliance/Evidence/`: {@see MeasuringComponent} seals
     * who may produce a fact and has never constrained what they may SAY, so an
     * estate the gatherer typed would have been one more sentence nothing checks.
     * Derived here, `database_transport_encrypted` is about the database link
     * whichever observer produces it, and mislabelling it is not expressible.
     * (What stays caller-supplied is the DETAIL prose, which no type can check.)
     *
     * Exhaustive, with no `default` arm, for the reason ADR-0045 gives: a fact
     * added to this vocabulary without an estate must be an unhandled match where
     * it is used, not a fact that quietly matches nothing — or, worse, everything.
     *
     * Read {@see ProbeVerdict::reach()} for what the estate decides, and
     * {@see ControlSubject} for why the vocabulary is narrow.
     */
    #[NoDiscard]
    public function subject(): ControlSubject
    {
        return match ($this) {
            // Resolved identity. Each names the estate the contract serves, never
            // the contract: TokenStoreInterface is wiring, the PAN estate is what
            // PCI Req 3.4 is about.
            self::TokenVaultPersistence => ControlSubject::CardholderData,
            self::AuditSinkResolved => ControlSubject::AuditTrail,
            self::EvidenceStoreResolved => ControlSubject::ComplianceEvidenceRegister,
            self::SessionEncryptionResolved => ControlSubject::SessionPayloads,
            self::MasterKeyResolved => ControlSubject::KeyHierarchy,
            self::MfaSubsystemResolved => ControlSubject::Authentication,
            self::IncidentReporterResolved => ControlSubject::IncidentResponse,
            self::BackupPrimitiveResolved => ControlSubject::BusinessContinuity,
            self::ConsentSubsystemResolved => ControlSubject::PersonalData,
            self::ErasureSubsystemResolved => ControlSubject::PersonalData,
            self::SubjectRequestHandlerResolved => ControlSubject::PersonalData,
            self::RetentionScheduleResolved => ControlSubject::PersonalData,
            self::PseudonymizationResolved,
            self::PseudonymTablePersistence => ControlSubject::PersonalData,
            self::ObservabilityExporterResolved => ControlSubject::OperationalMonitoring,
            self::AuthRateLimiterEnforcing => ControlSubject::Authentication,
            self::ComplianceProfileResolved => ControlSubject::RiskGovernance,
            self::SecurityFeaturesIntact => ControlSubject::DeploymentConfiguration,
            self::ClassifiedRouteCoverage => ControlSubject::RouteInventory,

            // The AI governance extension. One estate, because every one of these
            // reads the same subsystem: which AI contract answered inside it.
            self::AiGovernanceExtensionActive,
            self::AiModelRegistryResolved,
            self::AiImpactAssessmentResolved,
            self::AiAuditLoggerResolved,
            self::AiDataGovernanceResolved,
            self::AiExplainabilityResolved,
            self::AiLifecycleManagerResolved,
            self::AiDeploymentGateResolved,
            self::AiMonitoringHookResolved,
            self::AiTransparencyResolved => ControlSubject::AiSystemGovernance,

            // What the BUILD offers, kept apart from what this deployment derives
            // with. Both of these answer questions about the platform: which
            // extension is loaded, which cipher suite is on an approved list. Neither
            // is an answer about a payload, which is why nine controls could rest on
            // one of them.
            self::CryptographicCapability,
            self::FipsValidatedCryptography => ControlSubject::CryptographicPlatform,

            // Measured behaviour. AuditChainVerified is the one to read twice: it
            // recomputes HMACs over the COMPLIANCE EVIDENCE REGISTER, the framework's
            // signed log of its own verification runs, and not over the trail the
            // application writes through AuditSinkInterface. Those are two estates
            // and this vocabulary now says so.
            self::KeyDerivationVerified => ControlSubject::KeyHierarchy,
            self::AuditChainVerified => ControlSubject::ComplianceEvidenceRegister,
            self::HealthChecksExecuted => ControlSubject::OperationalMonitoring,
            self::DatabaseTransportEncrypted => ControlSubject::DatabaseTransport,
            self::TokenVaultRendersUnreadable => ControlSubject::CardholderData,
            // Session state and nothing wider. What was exercised is the cipher a
            // session payload passes through, so the estate is the session — not
            // "data at rest", which would let it answer for a database full of
            // records, and not PersonalData, which would let sealing a session
            // stand in for protecting everything GDPR Art 5(1)(f) covers.
            self::SessionPayloadsSealed => ControlSubject::SessionPayloads,
            // A value the deployment was told is personal data, and what it did
            // with it. The estate is {@see ControlSubject::PersonalData} and the
            // reason is the input rather than the payload: the framework's own
            // at-rest rule branches on a personal-data classification, so what was
            // interrogated is how this deployment treats a value carrying that
            // classification. It is deliberately NOT ConfidentialInformation —
            // `Restricted` takes the same branch and is a different estate, so a
            // fact minted from a Pii field cannot answer for contracts and pricing
            // — and NOT HealthData or CardholderData, which are siblings that no
            // amount of sealing an ordinary personal-data field speaks to.
            self::PersonalDataFieldSealed => ControlSubject::PersonalData,
            // The same estate the ten resolved AI facts above name, and reached the
            // other way round: those record which contract answered inside the AI
            // governance subsystem, this one records the subsystem having declared a
            // surface and marked an output. Article 50 is about how an AI system
            // presents itself to the people it meets, which is what
            // {@see ControlSubject::AiSystemGovernance} is; it is deliberately not
            // filed under a data estate, because nothing here interrogates a payload.
            self::AiTransparencyExercised => ControlSubject::AiSystemGovernance,
            // The same estate again, reached a third way. The ten resolved facts
            // record which contract answered; the transparency fact records the
            // subsystem having declared a surface and marked an output; these two
            // record the governance RECORD having outlived the process that wrote
            // it, and the monitoring having run and been retained. All of it is
            // {@see ControlSubject::AiSystemGovernance} and none of it is a data
            // estate: nothing here interrogates a payload, and a governance record
            // that survives says nothing about whether personal data is protected.
            self::AiGovernanceRecordsDurable,
            self::AiMonitoringExercised => ControlSubject::AiSystemGovernance,
            // A direct identifier, replaced and then erased. The estate is the data
            // — {@see ControlSubject::PersonalData} — and not "the pseudonymisation
            // subsystem", because that is the mistake this method exists to stop:
            // the fact answers a question about what happens to an identifier, which
            // is what Art 25 regulates. It is deliberately NOT filed under
            // ConfidentialInformation, so it cannot carry ISO 27001 A.8.12, which
            // regulates that estate and asks a broader question this measurement
            // does not answer.
            self::IdentifierPseudonymizedAndErased => ControlSubject::PersonalData,
            // An incident, recorded and given back intact. The estate is incident
            // response rather than personal data: what was exercised is the
            // register, and a control about personal data cannot be carried by a
            // register that has never held any.
            self::IncidentRecordedAndRetained => ControlSubject::IncidentResponse,
            // A backup, sealed, verified, refused when altered, and restored. The
            // estate is {@see ControlSubject::BusinessContinuity} — the same one
            // `backup_primitive_resolved` names — and deliberately not any data
            // estate: the round trip runs over a synthetic payload, so it says
            // what this deployment's recovery mechanism does and says nothing
            // about whether the personal data, cardholder data or health records
            // it holds are in any particular archive. Filing it under a data
            // estate would let a working backup mechanism answer for protecting
            // the data, which is the substitution this method exists to refuse.
            self::BackupRoundTripVerified => ControlSubject::BusinessContinuity,

            // Declared configuration.
            self::MasterKeyMaterial => ControlSubject::KeyHierarchy,
            self::TransportSecurityEnforced => ControlSubject::HttpTransport,
            self::SessionEncryptionConfigured,
            self::SessionCookiesHardened => ControlSubject::SessionPayloads,
            self::CsrfProtectionActive,
            self::SecurityHeadersConfigured,
            self::DebugModeDisabled => ControlSubject::DeploymentConfiguration,
            self::RetentionBounded => ControlSubject::PersonalData,
            self::BreachNotificationDeadlineSet => ControlSubject::IncidentResponse,
            self::PasswordPolicyEnforced,
            self::MfaPolicyRequired => ControlSubject::Authentication,

            // Operator scope assertions. The estate here is what the operator is
            // claiming to be out of play, and it is what decides which controls the
            // claim may retire — see {@see ProbeVerdict::reach()}.
            self::ScopeStoresCardholderData => ControlSubject::CardholderData,
            self::ScopeProcessesHealthData => ControlSubject::HealthData,
            self::ScopeProcessesPersonalData => ControlSubject::PersonalData,
        };
    }
}
