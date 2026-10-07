<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Evidence;

use NoDiscard;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\ComplianceProfile;
use Pulsar\Compliance\Control\ContractResolution;
use Pulsar\Compliance\Control\ControlEvidence;
use Pulsar\Compliance\Control\ExecutedSubject;
use Pulsar\Compliance\Control\IncompleteEvidenceException;
use Pulsar\Compliance\Control\Inspection;
use Pulsar\Compliance\Control\Measurement;
use Pulsar\Compliance\Control\Observation;
use Pulsar\Compliance\Control\ObservationId;
use Pulsar\Compliance\Control\PlatformCapability;
use Pulsar\Compliance\Verification\CheckResult;
use Pulsar\Compliance\Verification\CheckStatus;
use Pulsar\Compliance\Verification\DataPathVerifier;
use Pulsar\Compliance\Verification\EvidenceChain;
use Pulsar\Compliance\Verification\EvidenceChainVerdict;
use Pulsar\Config\DatabaseConfig;
use Pulsar\Core\Wiring\Contract\DegradedFeature;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Resilience\Backup\BackupDestination;
use Pulsar\Resilience\Backup\BackupServiceInterface;
use Pulsar\Resilience\HealthCheck\HealthCheckResult;
use Pulsar\Resilience\HealthCheck\HealthCheckRunnerInterface;
use Pulsar\Resilience\HealthCheck\HealthStatus;
use Pulsar\Security\Compliance\Pseudonymization\ForgetServiceInterface;
use Pulsar\Security\Compliance\Pseudonymization\PseudonymizationServiceInterface;
use Pulsar\Security\Crypto\EncryptorInterface;
use Pulsar\Security\Crypto\TokenizationServiceInterface;
use Pulsar\Security\Crypto\TokenStoreInterface;
use Pulsar\Security\Incident\IncidentReporterInterface;
use Pulsar\Security\Posture\SecurityPostureReport;
use Pulsar\Security\Posture\SecurityPostureStatus;
use Pulsar\Security\Session\SessionPayloadCipherInterface;
use Throwable;

use function array_map;
use function count;
use function implode;
use function in_array;
use function sprintf;

/**
 * Turns the wired deployment into the frozen fact set probes read.
 *
 * Every collaborator arrives through the constructor: this class never holds a
 * container and never resolves anything, which is what keeps the "no service
 * locator" rule true one level below the probes. The composition root does the
 * resolving, because that is the only place allowed to.
 *
 * THIS IS WHERE GRADES ARE ASSIGNED, and it is the design's softest joint.
 * Nothing in the type system can tell whether a method measured something or
 * read a config value; what keeps it honest is concentration and exposure. Every
 * grade in the system is decided in the handful of methods below — not spread
 * across two hundred controls — so the reviewable surface is small and stable.
 * And every {@see Observation} carries the class that produced it, printed beside
 * its grade in the report, so `measured … (SecurityPostureCheck)` reads wrong on
 * the page. That exact line was on the page: `master_key` was graded Measured
 * here for years because the posture check reads it from the environment. It is
 * Declared now; see {@see POSTURE_FACTS}.
 *
 * THE TEST A GRADE HAS TO PASS, applied per call site rather than per method,
 * because one method used to grade three unlike checks the same way: could this
 * fact come back differently on two deployments that differ in the thing the
 * control is about? `extension_loaded('sodium')` cannot, so it is Available.
 * Which cipher suite is bound can, but only by naming a class rather than by
 * running one, so it is Resolved. Running the KDF against the key in service can,
 * and does, so it is Measured. See {@see cryptographicCapability()},
 * {@see fipsValidatedCryptography()} and {@see keyDerivation()}, which are the
 * three halves of what was a single `fromRuntimeCheck()`.
 *
 * Three constraints shaped this class:
 *
 *  - `ExtensionRegistry` is #[Internal], so Compliance may not import it; the
 *    composition root reads it and passes plain extension ids.
 *  - Contract and implementation names travel as plain strings in
 *    {@see ResolvedBindings}, never as imported types. Half of them belong to
 *    extensions Compliance may not import, and one of them —
 *    `BACKUP_SERVICE_CONTRACT` — names a primitive the framework does not have,
 *    which is precisely the fact NIST CSF RC.RP must be able to state.
 *  - It must be bound as a LAZY singleton closure, resolved when the compliance
 *    report runs and never at boot. Eager resolution at wiring time is exactly
 *    the ordering bug ADR-0041 fixed for TokenStoreInterface: SecurityWiring is
 *    eighth in {@see \Pulsar\Core\Wiring\WiringList} and DatabaseWiring
 *    seventeenth, so gathering at boot would resolve the token vault before the
 *    database exists, and the report would then measure — and truthfully,
 *    chain-signed, record — a vault that the running application does not use.
 *
 * GATHERING HAS SIDE EFFECTS, and two of them write. It opens a database
 * session and queries it, it EXECUTES every registered health check, and it
 * recomputes one HMAC per stored evidence record. Then, through
 * {@see TokenVaultObserver}, it tokenizes one synthetic value, reads it back,
 * detokenizes it and removes it again; and through {@see AiTransparencyObserver}
 * it declares one reserved Article 50 surface, reads the policy back and mints a
 * synthetic-content mark. Each write is what separates a subsystem that works
 * from one that merely resolves, and nothing weaker distinguishes them; see those
 * two classes for what each leaves behind. The vault's row is removed, and the
 * transparency declaration cannot be — `AiTransparencyInterface` has no
 * withdrawal — so it is bounded to one entry under one reserved surface id
 * instead, and the observer proves that bound rather than asserting it.
 *
 * TWO MORE WRITE, both added to close the GDPR controls ADR-0046 deliberately
 * left failing, and they land on opposite sides of the same trade.
 * {@see PseudonymizationObserver} creates one pseudonym mapping and then ERASES
 * it — and the erasure is not tidying borrowed from elsewhere, it is Art 17,
 * which is the control `ForgetService` exists to perform, so what is left behind
 * is a table holding no mapping the check created. {@see IncidentRegisterObserver} records one
 * Low-severity incident and CANNOT take it back: `IncidentReporterInterface` has
 * no removal, and it should not grow one, because a register whose entries can be
 * deleted evidences nothing. That row is permanent, it says so about itself in
 * its own title, and the observer's docblock argues why it is worth writing.
 *
 * None of this belongs in a request boot, which is the other half of why the
 * binding is lazy.
 */
#[Internal(reason: 'Built by the composition root; probes receive only its output')]
final readonly class ControlEvidenceGatherer implements EvidenceSourceInterface
{
    /**
     * A backup and restore primitive, named so its absence is a fact rather than
     * a silence. Nothing in `src/` answers this contract: the only implementation
     * of anything like it lives in the CMS extension, for content. NIST CSF RC.RP
     * therefore reports a gap until someone builds the primitive, installs a
     * package that provides it, or removes NistCsf from enabled_frameworks — and
     * forcing that choice is the whole point.
     */
    public const string BACKUP_SERVICE_CONTRACT = 'Pulsar\Resilience\Backup\BackupServiceInterface';

    /**
     * The session cipher, named as a string because it is #[Internal] to the
     * Security module and Compliance may not import it.
     *
     * It is NOT in {@see IDENTITY_FACTS}, and that is the fix rather than an
     * oversight. It was, spelled `SessionEncryption => SessionEncryption`: a
     * concrete final class as both the contract and its only accepted
     * implementation, so the resolution carried exactly one bit — whether the
     * container built one — and grade Resolved made that bit admissible proof.
     * PCI Req 2.3 reached Satisfied on it. See {@see sessionCipherResolved()}.
     */
    private const string SESSION_CIPHER_CONTRACT = 'Pulsar\Security\Session\SessionEncryption';

    /** The AI governance extension's identifier in the extension registry. */
    public const string AI_GOVERNANCE_EXTENSION = 'pulsar/ai-governance';

    /** What the audit-chain measurement names as having run, in the report. */
    private const string AUDIT_CHAIN_SUBJECT = 'the stored evidence chain';

    /**
     * How each {@see EvidenceChainVerdict} is worded in the report.
     *
     * The verdicts are distinct findings rather than shades of one, and the
     * report has to keep them distinct: "the register is TRUNCATED" and "the
     * register is MODIFIED" send an assessor to two different questions and an
     * operator to two different remedies. Keyed by case NAME so a case added to
     * the enum without a word here is a missing-key error at the point of use
     * rather than a control that silently reports something bland.
     *
     * @var array<string, string>
     */
    private const array CHAIN_VERDICT_WORDS = [
        'Intact' => 'INTACT',
        'Empty' => 'EMPTY',
        'Modified' => 'MODIFIED',
        'Truncated' => 'TRUNCATED',
        'Reordered' => 'REORDERED',
        'Unreadable' => 'UNREADABLE',
        'Unanchored' => 'UNANCHORED',
        'KeyUnavailable' => 'SIGNED UNDER A KEY THIS DEPLOYMENT DOES NOT HOLD',
    ];

    /** What the health-check measurement names as having run, in the report. */
    private const string HEALTH_CHECK_SUBJECT = 'the registered health checks';

    /**
     * The contracts whose RESOLVED IDENTITY is a compliance fact, with the
     * implementations this release has assessed as discharging them.
     *
     * Accept lists, not reject lists, and the difference matters. A reject list
     * accepts by default, so the next null object someone adds is evidence until
     * a reviewer notices; an accept list refuses by default, so a `NoopSpanProcessor`
     * — which exists in this tree — is absent from the list as a visible decision.
     * The cost is real and taken deliberately: a deployment's own durable
     * implementation that this release has never heard of is reported unobserved
     * rather than assumed adequate. The report says exactly that, naming the class,
     * so an operator can see it is an unassessed implementation and not a missing one.
     *
     * `inert` names implementations known NOT to discharge the control and why, so
     * the report can say what stood there instead of merely that nothing did.
     *
     * NO ENTRY BELOW ACCEPTS A DOCUMENTED DEVELOPMENT STUB, and six used to. An
     * accept list containing `InMemoryModelRegistry` — whose own attribute reads
     * `#[Internal(reason: 'Development store; production deployments should use a
     * persistent implementation')]` — is ADR-0041's defect with an extra step: the
     * control was satisfied because a class existed AND was bound, and the class
     * says in its own source that it does not do the job. A store that loses its
     * contents when the process restarts cannot evidence anything after the
     * restart, which is when an assessor asks. Every one of them is now listed
     * under `inert` with the reason, so the report names the stub rather than
     * passing on it — and several accept lists are consequently EMPTY, which is
     * the honest statement that this release ships no implementation that
     * discharges the contract. That is the same statement
     * {@see BACKUP_SERVICE_CONTRACT} makes, and it is meant to be uncomfortable.
     *
     * Nor does any entry name a concrete final class as both the contract and its
     * only accepted implementation. `X -> X` carries exactly one bit — whether the
     * container built an X — and "the class is bound" is the claim
     * {@see ObservationGrade} has no case for. Where a real contract with real
     * alternatives exists it is named instead; where none does, the fact is
     * produced as a configuration read rather than dressed as a resolution.
     *
     * @var array<string, array{contract: string, role: string, accepts: list<string>, inert: array<string, string>}>
     */
    private const array IDENTITY_FACTS = [
        ObservationId::TokenVaultPersistence->value => [
            'contract' => 'Pulsar\Security\Crypto\TokenStoreInterface',
            'role' => 'holds the tokens that stand in for primary account numbers',
            'accepts' => ['Pulsar\Security\Crypto\DatabaseTokenStore'],
            'inert' => [
                'Pulsar\Security\Crypto\InMemoryTokenStore' =>
                    'tokens are held in process memory and lost on restart, leaving the PANs they '
                    . 'replaced unrecoverable (ADR-0041)',
            ],
        ],
        ObservationId::AuditSinkResolved->value => [
            'contract' => 'Pulsar\Security\Audit\AuditSinkInterface',
            'role' => 'persists the audit trail',
            'accepts' => ['Pulsar\Security\Audit\AuditFileSink'],
            'inert' => [],
        ],
        ObservationId::EvidenceStoreResolved->value => [
            'contract' => 'Pulsar\Compliance\Evidence\EvidenceStoreInterface',
            'role' => 'stores the chained compliance evidence records',
            // Empty, and it is the framework's gap rather than the deployment's:
            // InMemoryEvidenceStore is the only implementation in this tree.
            'accepts' => [],
            'inert' => [
                'Pulsar\Compliance\Evidence\InMemoryEvidenceStore' =>
                    'its own docblock reads "for testing and development. Production deployments '
                    . 'should use a persistent store" — records are held in process memory, so the '
                    . 'chain an assessor would verify does not survive the request that wrote it',
            ],
        ],
        ObservationId::MasterKeyResolved->value => [
            // Not `MasterKey => MasterKey`, which asked whether the container had
            // built a MasterKey and dressed the answer as a resolved identity. The
            // contract is the interface it implements, and it has a second
            // implementation the deployment may genuinely be running: an operator
            // who sets per-subkey overrides is served by CompositeKeyProvider, and
            // which of the two answers is a real fact about the deployment.
            'contract' => 'Pulsar\Security\Crypto\KeyProviderInterface',
            'role' => 'provides the key material every domain-separated subkey is derived from',
            'accepts' => [
                'Pulsar\Security\Crypto\MasterKey',
                'Pulsar\Security\Crypto\CompositeKeyProvider',
            ],
            'inert' => [],
        ],
        ObservationId::MfaSubsystemResolved->value => [
            'contract' => 'Pulsar\Auth\TwoFactor\TwoFactorManagerInterface',
            'role' => 'enrols and verifies second authentication factors',
            'accepts' => ['Pulsar\Auth\TwoFactor\TwoFactorManager'],
            'inert' => [],
        ],
        ObservationId::AuthRateLimiterEnforcing->value => [
            'contract' => 'Pulsar\Auth\TwoFactor\TwoFactorRateLimiterInterface',
            'role' => 'throttles second-factor guessing',
            'accepts' => [],
            'inert' => [
                'Pulsar\Auth\TwoFactor\AllowAllTwoFactorRateLimiter' =>
                    'every attempt is allowed, so the limiter enforces nothing',
            ],
        ],
        ObservationId::IncidentReporterResolved->value => [
            'contract' => 'Pulsar\Security\Incident\IncidentReporterInterface',
            'role' => 'records security incidents for the notification deadline',
            'accepts' => ['Pulsar\Security\Incident\FileIncidentReporter'],
            'inert' => [
                'Pulsar\Security\Incident\InMemoryIncidentReporter' =>
                    'incidents are lost on restart, so no notification deadline can be evidenced',
            ],
        ],
        ObservationId::ConsentSubsystemResolved->value => [
            'contract' => 'Pulsar\DataProtection\ConsentManagerInterface',
            'role' => 'records and withdraws data-subject consent',
            'accepts' => [],
            'inert' => [
                'Pulsar\DataProtection\InMemoryConsentManager' =>
                    'consent records vanish on restart, so consent cannot be demonstrated afterwards',
            ],
        ],
        ObservationId::ErasureSubsystemResolved->value => [
            'contract' => 'Pulsar\DataProtection\DataPurgeInterface',
            'role' => 'erases personal data on request or on schedule',
            'accepts' => [
                'Pulsar\DataProtection\DataPurgeOrchestrator',
                'Pulsar\DataProtection\AuditLogPurge',
                'Pulsar\DataProtection\SessionPurge',
            ],
            'inert' => [],
        ],
        ObservationId::SubjectRequestHandlerResolved->value => [
            'contract' => 'Pulsar\DataProtection\Dsar\DsarStoreInterface',
            'role' => 'receives, tracks and answers data-subject access requests',
            'accepts' => [],
            'inert' => [],
        ],
        ObservationId::RetentionScheduleResolved->value => [
            'contract' => 'Pulsar\DataProtection\RetentionPolicyInterface',
            'role' => 'decides how long each data class is kept',
            'accepts' => ['Pulsar\DataProtection\DefaultRetentionPolicy'],
            'inert' => [],
        ],
        ObservationId::PseudonymizationResolved->value => [
            'contract' => 'Pulsar\Security\Compliance\Pseudonymization\PseudonymizationServiceInterface',
            'role' => 'replaces direct identifiers with reversible pseudonyms',
            'accepts' => ['Pulsar\Security\Compliance\Pseudonymization\PseudonymizationService'],
            'inert' => [],
        ],
        // The table the mappings rest in, and the fact no in-process measurement
        // can produce. {@see PseudonymizationObserver} mints a pseudonym, resolves
        // it and erases it against whatever lookup is bound, and all four of its
        // subjects pass over `InMemoryPseudonymLookup` — which has forgotten every
        // mapping by the next request, so the pseudonymised records it produced can
        // never be re-identified for an Art 15 answer and there is nothing left for
        // Art 17 to erase. This is the TokenVaultPersistence pattern applied to the
        // one other subsystem in the tree with the same shape.
        ObservationId::PseudonymTablePersistence->value => [
            'contract' => 'Pulsar\Security\Compliance\Pseudonymization\PseudonymLookupInterface',
            'role' => 'holds the mappings between subject identifiers and their pseudonyms',
            'accepts' => ['Pulsar\Security\Compliance\Pseudonymization\FilePseudonymLookup'],
            'inert' => [
                'Pulsar\Security\Compliance\Pseudonymization\InMemoryPseudonymLookup' =>
                    'its own #[Internal] reason reads "Test/dev pseudonym lookup implementation" — the '
                    . 'mappings are held in process memory, so a pseudonymised record cannot be '
                    . 'resolved back after a restart and an erasure request has nothing to erase',
            ],
        ],
        ObservationId::ObservabilityExporterResolved->value => [
            'contract' => 'Pulsar\Observability\Tracing\SpanProcessorInterface',
            'role' => 'exports traces off the box, where detection can act on them',
            'accepts' => [
                'Pulsar\Extension\OpenTelemetry\Bridge\OtlpTracerBridge',
                'Pulsar\Extension\OpenTelemetry\Bridge\CompositeSpanProcessor',
                'Pulsar\Extension\Observability\Tracing\Bridge\OtlpTracerBridge',
                'Pulsar\Extension\Observability\Tracing\Bridge\CompositeSpanProcessor',
            ],
            'inert' => [
                'Pulsar\Extension\OpenTelemetry\Noop\NoopSpanProcessor' =>
                    'spans are discarded, so nothing leaves the process',
                'Pulsar\Extension\Observability\Tracing\Noop\NoopSpanProcessor' =>
                    'spans are discarded, so nothing leaves the process',
                'Pulsar\Observability\Tracing\InMemorySpanCollector' =>
                    'spans are held in process memory and never exported',
            ],
        ],
        ObservationId::BackupPrimitiveResolved->value => [
            'contract' => self::BACKUP_SERVICE_CONTRACT,
            'role' => 'takes and restores backups, which recovery depends on',
            // The accept list stopped being empty when the framework grew the
            // primitive. It stays a list rather than "anything bound", for the
            // reason every accept list in this table does: an implementation this
            // release has not assessed reads as unassessed instead of as adequate.
            //
            // And it decides nothing on its own. This fact is SUPPORTING in
            // {@see \Pulsar\Compliance\Probe\RecoveryCapabilityProbe} and required
            // by nothing — what carries the three recovery controls is
            // {@see ObservationId::BackupRoundTripVerified}, which runs a real
            // round trip. Promoting this one back to required would restore the
            // exact shape ADR-0041 recorded, in the control that shipped without
            // any carrier at all.
            'accepts' => ['Pulsar\Resilience\Backup\SealedArchiveBackupService'],
            'inert' => [],
        ],
        ObservationId::AiModelRegistryResolved->value => [
            'contract' => 'Pulsar\Extension\AiGovernance\Contracts\AiModelRegistryInterface',
            'role' => 'records the AI models in service and their lifecycle state',
            'accepts' => ['Pulsar\Extension\AiGovernance\Internal\Store\DbModelRegistry'],
            'inert' => [
                'Pulsar\Extension\AiGovernance\Internal\Store\InMemoryModelRegistry' =>
                    'the extension ships it as a development store; the model inventory is held in '
                    . 'process memory, so nothing records which models were in service once the '
                    . 'process that registered them ends',
            ],
        ],
        ObservationId::AiImpactAssessmentResolved->value => [
            'contract' => 'Pulsar\Extension\AiGovernance\Contracts\AiImpactAssessmentInterface',
            'role' => 'assesses an AI system\'s impact on individuals and groups',
            'accepts' => ['Pulsar\Extension\AiGovernance\Internal\Store\DbImpactAssessmentStore'],
            'inert' => [
                'Pulsar\Extension\AiGovernance\Internal\Store\InMemoryImpactAssessmentStore' =>
                    'a development store whose assess() performs no assessment — it creates an empty '
                    . 'finding list and returns, so the control would rest on findings a human typed '
                    . 'in and the process has since forgotten',
            ],
        ],
        ObservationId::AiAuditLoggerResolved->value => [
            'contract' => 'Pulsar\Extension\AiGovernance\Contracts\AiAuditLoggerInterface',
            'role' => 'logs model invocations, decisions and human overrides',
            'accepts' => ['Pulsar\Extension\AiGovernance\Internal\AiAuditLogger'],
            'inert' => [],
        ],
        ObservationId::AiDataGovernanceResolved->value => [
            'contract' => 'Pulsar\Extension\AiGovernance\Contracts\AiDataGovernanceInterface',
            'role' => 'tracks training-data provenance, quality and consent',
            'accepts' => ['Pulsar\Extension\AiGovernance\Internal\Store\DbDataGovernanceStore'],
            'inert' => [
                'Pulsar\Extension\AiGovernance\Internal\Store\InMemoryDataGovernanceStore' =>
                    'a development store; provenance and quality reports are held in process memory '
                    . 'and lost on restart',
                'Pulsar\Extension\AiGovernance\Internal\ConsentEnforcingDataGovernance' =>
                    'a decorator that rejects provenance recorded without consent — a real guarantee, '
                    . 'but one about the boundary and not about the record. What it wraps is invisible '
                    . 'from here, and it is the store underneath that decides whether the record '
                    . 'survives the process; ai_governance_records_durable is the fact that reaches it',
            ],
        ],
        ObservationId::AiExplainabilityResolved->value => [
            'contract' => 'Pulsar\Extension\AiGovernance\Contracts\ExplainabilityInterface',
            'role' => 'explains a model decision to the person it affected',
            'accepts' => ['Pulsar\Extension\AiGovernance\Internal\Store\DbExplainabilityStore'],
            'inert' => [
                'Pulsar\Extension\AiGovernance\Internal\Store\InMemoryExplainabilityStore' =>
                    'a development store; the explanation a data subject is entitled to is held in '
                    . 'process memory and gone before they ask for it',
            ],
        ],
        ObservationId::AiLifecycleManagerResolved->value => [
            'contract' => 'Pulsar\Extension\AiGovernance\Contracts\AiLifecycleManagerInterface',
            'role' => 'moves models between lifecycle stages and can roll them back',
            'accepts' => ['Pulsar\Extension\AiGovernance\Internal\AiLifecycleManager'],
            'inert' => [],
        ],
        ObservationId::AiDeploymentGateResolved->value => [
            'contract' => 'Pulsar\Extension\AiGovernance\Contracts\DeploymentGateInterface',
            'role' => 'refuses a model deployment that fails its pre-production checks',
            'accepts' => [
                'Pulsar\Extension\AiGovernance\Internal\Gate\ImpactAssessmentGate',
                'Pulsar\Extension\AiGovernance\Internal\Gate\ModelCardGate',
            ],
            'inert' => [],
        ],
        ObservationId::AiMonitoringHookResolved->value => [
            'contract' => 'Pulsar\Extension\AiGovernance\Contracts\MonitoringHookInterface',
            'role' => 'runs production monitoring checks against a deployed model',
            // The contract that had ZERO implementations anywhere in the tree until
            // rc.12, which is why this list was empty and why Clause 9.1 could not be
            // satisfied by any deployment. The shipped hook is named here, and it is
            // deliberately NOT what carries the clause: this fact is a resolution, and
            // {@see ObservationId::AiMonitoringExercised} — a hook having RUN and its
            // result having been retained — is what the mapping reads now.
            'accepts' => ['Pulsar\Extension\AiGovernance\Internal\Monitoring\GovernanceConformityHook'],
            'inert' => [],
        ],
        ObservationId::AiTransparencyResolved->value => [
            'contract' => 'Pulsar\Extension\AiGovernance\Contracts\AiTransparencyInterface',
            'role' => 'holds the Article 50 positions this deployment has declared, and mints the '
                . 'machine-readable marks for generated output',
            'accepts' => ['Pulsar\Extension\AiGovernance\Internal\Store\InMemoryAiTransparency'],
            'inert' => [],
        ],
    ];

    /**
     * The security-posture items whose value is a compliance fact, and the
     * observation each becomes.
     *
     * ALL DECLARED, WITHOUT AN EXCEPTION. There used to be one: `master_key` was
     * graded Measured because {@see \Pulsar\Security\Posture\SecurityPostureCheck}
     * reads it from the process environment rather than from a config file, and
     * the docblock called that "a property of the running process". Where a
     * configured value is read from does not change what reading it establishes.
     * Follow what that check does — it reads PULSAR_MASTER_KEY, confirms the
     * string parses into a key, and reads two booleans saying whether the wiring
     * bound a MasterKey and an encryptor. A parse is a validation of a
     * configured value, and binding presence is the claim
     * {@see \Pulsar\Compliance\Control\ObservationGrade} deliberately has no
     * case for. Nothing there encrypts anything, so Declared is the grade, and
     * Declared already means exactly this.
     *
     * The fact this moves — {@see ObservationId::MasterKeyMaterial} — is
     * SUPPORTING in every probe that names it and required by none, so the regrade
     * moves no control in any direction. What it changes is the evidence column: an
     * assessor stops reading "(measured)" under seven controls that measured no
     * cryptography. The one fact in this area that IS measured is
     * {@see keyDerivation()}, which runs the KDF against the key in service.
     *
     * @var array<string, string> posture item name => ObservationId value
     */
    private const array POSTURE_FACTS = [
        'csrf_protection' => ObservationId::CsrfProtectionActive->value,
        'https_hsts' => ObservationId::TransportSecurityEnforced->value,
        'session_encryption' => ObservationId::SessionEncryptionConfigured->value,
        'security_headers' => ObservationId::SecurityHeadersConfigured->value,
        'debug_mode' => ObservationId::DebugModeDisabled->value,
        'master_key' => ObservationId::MasterKeyMaterial->value,
    ];

    /** The three cookie items folded into one fact: hardening is all-or-nothing. */
    private const array COOKIE_POSTURE_ITEMS = [
        'session_cookie_secure',
        'session_cookie_httponly',
        'session_cookie_samesite',
    ];

    /**
     * @param list<CheckResult>     $runtimeChecks     {@see \Pulsar\Compliance\Verification\RuntimeVerifier::verify()}, unchanged
     * @param list<DegradedFeature> $degradedFeatures  {@see \Pulsar\Core\Wiring\Contract\WiringContractInspector::degradedFeatures()}
     * @param list<string>          $inspectedWiringContracts The components whose wiring contracts
     *        the inspector actually read. Passed so that an EMPTY $degradedFeatures can be told
     *        apart from an inspection that never happened: "no security feature is inert" over a
     *        population nobody enumerated is a pass produced by the absence of a measurement
     * @param list<string>          $activeExtensions  Extension ids, read from the registry by the composition root
     * @param AiTransparencyDrillInterface|null $transparencyDrill The seam an AI governance
     *        extension binds so its Article 50 subsystem can be exercised. Nullable because the
     *        extension is optional at trust tier `verified`, and null is the whole point: the
     *        observer then reports that nothing was exercised, which is a gap, rather than
     *        reporting false. Typed against a contract this module DECLARES rather than against
     *        `AiTransparencyInterface`, which belongs to the extension and is named nowhere in
     *        `src/`; see {@see AiTransparencyDrillInterface} for why the arrow points this way
     * @param AiGovernanceDrillInterface|null $governanceDrill The seam the same extension binds so
     *        that its model registry, impact assessment store, data governance store,
     *        explainability store and monitoring record store can be EXERCISED. Before it existed,
     *        thirteen ISO 42001 controls rested on {@see IDENTITY_FACTS} entries that grade a store
     *        by recognising its class name, which says nothing about a store the list has not been
     *        told about and nothing about what any store actually does
     * @param int                   $chainVerificationLimit How many of the most recent evidence records to
     *        recompute the signature of. Bounded on purpose: that part of verification is one HMAC
     *        per record, and "verified 500 of 40,000 records" is a materially different claim from
     *        "verified", so the bound and the actual counts are stated in the observation itself.
     *        The bound does NOT reach the anchor or the positions — completeness and ordering are
     *        established for the whole register on every run, because neither costs a hash.
     */
    public function __construct(
        private SecurityPostureReport $posture,
        private array $runtimeChecks,
        private array $degradedFeatures,
        private array $activeExtensions,
        private ComplianceScope $scope,
        private ResolvedBindings $bindings,
        private DatabaseTlsObserver $databaseTls,
        private RouteInventory $routes,
        private TokenVaultObserver $tokenVault,
        // THE OBSERVERS ARE CONCRETE ON PURPOSE, and the substitutability gate is
        // told so rather than argued with: three of the parameters in this
        // constructor already sit in tools/php/substitutability-baseline.json for
        // this reason, and AiTransparencyObserver joined them. An injectable seam
        // here would be a hole in the seal ADR-0050 built — MeasuringComponent lets
        // only a class declared inside src/Compliance/Evidence/ produce an
        // Observation, so a substitute from outside could not produce one at all
        // and a substitute from inside is a new producer rather than a decoration.
        // The only reason the five observers beside it escape the gate and this
        // one does not is that they hold a Randomizer and it holds nothing: its
        // probe material is fixed, deliberately, because the generation instant it
        // hands in and demands back IS the measurement.
        private AiTransparencyObserver $aiTransparency,
        // The two that took the ISO 42001 mapping off resolutions. They are
        // concrete for the same reason every observer above is, and they hold
        // nothing for the same reason AiTransparencyObserver holds nothing: the
        // records they write are fixed values, because the instants they hand in
        // and demand back are part of the measurement.
        private AiGovernanceRecordObserver $aiGovernanceRecords,
        private AiMonitoringObserver $aiMonitoring,
        private SessionSealObserver $sessionSeal,
        private PseudonymizationObserver $pseudonymization,
        private IncidentRegisterObserver $incidentRegister,
        private PersonalDataSealObserver $personalDataSeal,
        // The seventh observer, and the one that closed a control with no carrier
        // at all rather than one with a weak carrier: `backup_primitive_resolved`
        // named a contract nothing in `src/` answered, so NIST CSF RC.RP, SOC 2
        // A1.3 and HIPAA 164.308(a)(7) reported a primitive the framework did not
        // have. It holds a Randomizer, like the five beside it, and for the same
        // reason: the payload it seals and demands back IS the measurement.
        private BackupRoundTripObserver $backupRoundTrip,
        private ?DataPathVerifier $dataPaths = null,
        private ?ComplianceProfile $profile = null,
        private ?DatabaseConfig $databaseConfig = null,
        private ?ConnectionInterface $connection = null,
        private ?EvidenceChain $evidenceChain = null,
        private ?HealthCheckRunnerInterface $health = null,
        private ?TokenizationServiceInterface $tokenizer = null,
        private ?TokenStoreInterface $tokenStore = null,
        private ?AiTransparencyDrillInterface $transparencyDrill = null,
        // The seam the same extension binds so its governance RECORD and its
        // monitoring can be exercised, rather than recognised by class name.
        // Nullable for the reason the transparency drill is: the package is
        // optional, and a deployment without it must produce an ABSENT fact rather
        // than a false one.
        private ?AiGovernanceDrillInterface $governanceDrill = null,
        private ?SessionPayloadCipherInterface $sessionCipher = null,
        // The encryptor every subsystem that seals a classified value at rest is
        // handed. Held so that {@see PersonalDataSealObserver} can put the
        // deployment's own at-rest RULE through its work — see that class for why
        // the fact it produces is about personal data and not about this object.
        private ?EncryptorInterface $encryptor = null,
        // The two halves of pseudonymisation, resolved SEPARATELY for the reason
        // the vault's two halves are: the step that erases is the control Art 17
        // names, and taking it from the service that minted would let a deployment
        // evidence erasure with a service that only claims to erase.
        private ?PseudonymizationServiceInterface $pseudonymizer = null,
        private ?ForgetServiceInterface $forgetService = null,
        private ?IncidentReporterInterface $incidentReporter = null,
        // The backup service that would take this deployment's backups, and the
        // destination its archives actually go to. Both null on a deployment with
        // no master key: BackupWiring fails closed rather than binding a service
        // that would write an unsealed archive, and the round-trip fact is then
        // ABSENT — which is the correct answer, not a pass with a footnote.
        private ?BackupServiceInterface $backupService = null,
        private ?BackupDestination $backupDestination = null,
        private array $inspectedWiringContracts = [],
        private int $chainVerificationLimit = 500,
    ) {}

    /**
     * Produce exactly one observation per {@see ObservationId}.
     *
     * @throws IncompleteEvidenceException when a fact was not produced — which is
     *         a defect in this class, never in the deployment being assessed
     */
    #[NoDiscard]
    #[Override]
    public function gather(): ControlEvidence
    {
        $observations = [];

        foreach (self::IDENTITY_FACTS as $id => $fact) {
            $observations[] = $this->identity(ObservationId::from($id), $fact);
        }

        foreach (self::POSTURE_FACTS as $item => $id) {
            $observations[] = $this->fromPosture($item, ObservationId::from($id));
        }

        $observations[] = $this->sessionCipherResolved();
        $observations[] = $this->sessionSeal->observe($this->sessionCipher);
        $observations[] = $this->cookieHardening();
        $observations[] = $this->complianceProfile();
        $observations[] = $this->securityFeaturesIntact();
        $observations[] = $this->dataPaths !== null
            ? $this->routes->observe($this->dataPaths)
            : $this->routeCoverageUndetermined();
        $observations[] = $this->databaseTls->observe($this->databaseConfig, $this->connection);
        $observations[] = $this->tokenVault->observe($this->tokenizer, $this->tokenStore);
        $observations[] = $this->aiGovernanceExtension();
        $observations[] = $this->aiTransparency->observe($this->transparencyDrill);
        $observations[] = $this->aiGovernanceRecords->observe($this->governanceDrill);
        $observations[] = $this->aiMonitoring->observe($this->governanceDrill);
        $observations[] = $this->pseudonymization->observe($this->pseudonymizer, $this->forgetService);
        $observations[] = $this->incidentRegister->observe($this->incidentReporter);
        $observations[] = $this->personalDataSeal->observe($this->encryptor);
        $observations[] = $this->backupRoundTrip->observe($this->backupService, $this->backupDestination);
        $observations[] = $this->cryptographicCapability();
        $observations[] = $this->fipsValidatedCryptography();
        $observations[] = $this->keyDerivation();
        $observations[] = $this->auditChain();
        $observations[] = $this->healthChecks();
        $observations[] = $this->retentionBounded();
        $observations[] = $this->breachDeadline();
        $observations[] = $this->passwordPolicy();
        $observations[] = $this->mfaPolicy();

        foreach ($this->scope->assertions() as $assertion) {
            $observations[] = $assertion;
        }

        return ControlEvidence::gathered(...$observations);
    }

    // -- Resolved implementation identity ------------------------------------

    /**
     * Which concrete class answered a contract, judged against the accept list.
     *
     * Never "is something bound": the answer is a class name, and
     * `InMemoryTokenStore` and `DatabaseTokenStore` are different facts rather
     * than the same "bound".
     *
     * @param array{contract: string, role: string, accepts: list<string>, inert: array<string, string>} $fact
     */
    private function identity(ObservationId $id, array $fact): Observation
    {
        $concrete = $this->bindings->concreteFor($fact['contract']);

        return Observation::resolved(
            $id,
            $concrete === null
                ? ContractResolution::unanswered($fact['contract'], $fact['role'])
                : ContractResolution::answeredBy(
                    $fact['contract'],
                    $fact['role'],
                    $concrete,
                    $fact['accepts'],
                    $fact['inert'],
                ),
            self::class,
        );
    }

    /**
     * Whether a session cipher was built, reported as the configuration read it is.
     *
     * DELIBERATELY DECLARED, not Resolved, and this is the second of the two
     * entries review found naming a concrete final class as both the contract and
     * its only accepted implementation. `SessionEncryption -> SessionEncryption`
     * answers one question — did the container build one — and "the class is
     * bound" is the claim {@see \Pulsar\Compliance\Control\ObservationGrade} has
     * no case for, because it is ADR-0041's defect one level down. Dressed as a
     * resolved identity it was admissible proof, and it carried PCI Req 2.3 to
     * Satisfied in this very repository.
     *
     * It could not be fixed the way {@see ObservationId::MasterKeyResolved} was,
     * by naming an interface instead. There is one NOW —
     * {@see SessionPayloadCipherInterface} — and naming it here would change
     * nothing that matters: the Security module has exactly one implementation of
     * it, so the resolution would still carry the single bit "did the container
     * build one", dressed in a longer sentence. So this stays a config read, and
     * the two sentences it prints stay the two things that were actually
     * established.
     *
     * WHAT THE INTERFACE DID CHANGE is the sentence that used to follow: "nor can
     * it be measured from here". Exercising the cipher means holding one, and
     * `SessionEncryption` is #[Internal] to the Security module, so Compliance
     * could not — and the control had nowhere else to go. The contract publishes
     * sealing and opening without publishing a key, an algorithm or a key id, and
     * {@see SessionSealObserver} exercises it: see
     * {@see ObservationId::SessionPayloadsSealed}, which is the fact that can
     * actually carry a control about session payloads. This one no longer has to.
     *
     * The binding is not nothing, and it is not redundant beside the measurement
     * either. SecurityWiring constructs the cipher only when
     * `security.session.encryption` is true AND the master key loaded, and it
     * hands THAT instance to the session manager, so this fact is what says the
     * cipher measured beside it is the cipher in the write path. It still records
     * an intention that was acted on rather than a payload observed sealed, and
     * Declared is the grade for that.
     */
    private function sessionCipherResolved(): Observation
    {
        $concrete = $this->bindings->concreteFor(self::SESSION_CIPHER_CONTRACT);

        return $concrete === null
            ? Observation::declaredUnmet(
                ObservationId::SessionEncryptionResolved,
                sprintf(
                    'Nothing answered %s, so session payloads are written in cleartext. Either '
                        . 'security.session.encryption is false in config/security.php, or the '
                        . 'master key from which the cipher is derived never loaded.',
                    self::SESSION_CIPHER_CONTRACT,
                ),
                self::class,
            )
            : Observation::declaredMet(
                ObservationId::SessionEncryptionResolved,
                sprintf(
                    'config/security.php session.encryption is enabled and %s was constructed '
                        . 'from the master key. This records the request and the binding it '
                        . 'produced; no session payload was observed encrypted.',
                    $concrete,
                ),
                self::class,
            );
    }

    /**
     * Route-level coverage cannot be judged without a profile: which middleware a
     * classification requires depends on what the enabled frameworks demand, so
     * with no profile there is no standard to hold the routes to.
     */
    private function routeCoverageUndetermined(): Observation
    {
        return Observation::inspected(
            ObservationId::ClassifiedRouteCoverage,
            Inspection::nothingToInspect(
                'classified routes',
                'No ComplianceProfile was resolved, so the middleware each classification '
                    . 'requires is undetermined and no route could be checked against it.',
            ),
            self::class,
        );
    }

    private function complianceProfile(): Observation
    {
        if ($this->profile === null) {
            return Observation::inspected(
                ObservationId::ComplianceProfileResolved,
                Inspection::nothingToInspect(
                    'frameworks the resolved profile enables',
                    'No ComplianceProfile was resolved into the container, so no framework '
                        . 'requirement was applied to session, password or retention settings.',
                ),
                self::class,
            );
        }

        $names = array_map(
            static fn(ComplianceFramework $framework): string => $framework->value,
            $this->profile->enabledFrameworks,
        );

        if ($names === []) {
            return Observation::inspected(
                ObservationId::ComplianceProfileResolved,
                Inspection::nothingToInspect(
                    'frameworks the resolved profile enables',
                    'A ComplianceProfile was resolved but names no framework, so it tightened nothing.',
                ),
                self::class,
            );
        }

        return Observation::inspected(
            ObservationId::ComplianceProfileResolved,
            Inspection::coverage(
                'frameworks the resolved profile enables',
                $names,
                [],
                sprintf(
                    'ComplianceProfile resolved over %s and applied to session, password and retention settings.',
                    implode(', ', $names),
                ),
            ),
            self::class,
        );
    }

    /**
     * The inert-feature veto.
     *
     * {@see \Pulsar\Core\Wiring\Contract\WiringContractInspector} is the only
     * mechanism in the tree that distinguishes "bound" from "working" — it is what
     * caught CacheWiring failing to bind TaggedCacheInterface and three anti-spam
     * features going silently inert. Stated positively so `present` keeps its
     * meaning: true when no security feature is inert.
     */
    private function securityFeaturesIntact(): Observation
    {
        $inert = [];

        foreach ($this->degradedFeatures as $feature) {
            if ($feature->security) {
                $inert[] = $feature->describe();
            }
        }

        // An empty defect list is only good news when somebody looked. A gatherer
        // handed no inspected components has not found the wiring clean; it has
        // not been told anything — and "No security feature is inert" published
        // from that is the absence of a measurement reported as a result, at a
        // grade that would carry a control.
        if ($this->inspectedWiringContracts === []) {
            return Observation::inspected(
                ObservationId::SecurityFeaturesIntact,
                Inspection::nothingToInspect(
                    'security features the wiring contracts declare',
                    'No wiring contract was inspected, so nothing is established about whether '
                        . 'an optional security binding was left unsatisfied.',
                ),
                self::class,
            );
        }

        return Observation::inspected(
            ObservationId::SecurityFeaturesIntact,
            Inspection::defectScan(
                'security features the wiring contracts declare',
                $inert,
                $inert === []
                    ? sprintf(
                        'No security feature is inert: every optional binding declared by the %d '
                            . 'inspected wiring contract(s) was satisfied.',
                        count($this->inspectedWiringContracts),
                    )
                    : sprintf(
                        '%d security feature(s) are bound but inert — %s',
                        count($inert),
                        implode(' | ', $inert),
                    ),
            ),
            self::class,
        );
    }

    private function aiGovernanceExtension(): Observation
    {
        $active = in_array(self::AI_GOVERNANCE_EXTENSION, $this->activeExtensions, true);

        return Observation::inspected(
            ObservationId::AiGovernanceExtensionActive,
            Inspection::membership(
                'registered and booted extensions',
                self::AI_GOVERNANCE_EXTENSION,
                $this->activeExtensions,
                $active
                    ? sprintf('Extension %s is registered and booted.', self::AI_GOVERNANCE_EXTENSION)
                    : sprintf(
                        'Extension %s is not installed; every ISO/IEC 42001 control depends on it. '
                            . 'Active extensions: %s.',
                        self::AI_GOVERNANCE_EXTENSION,
                        $this->activeExtensions === [] ? 'none' : implode(', ', $this->activeExtensions),
                    ),
            ),
            self::class,
        );
    }

    // -- What the platform offers, what was bound, and what actually ran ------

    /**
     * Whether the cryptography the framework depends on EXISTS on this platform.
     *
     * Available, and it used to say Measured with the sentence "the algorithms
     * answer for themselves rather than a setting answering for them". They do
     * not. {@see \Pulsar\Compliance\Verification\RuntimeVerifier::verify()} calls
     * `extension_loaded('sodium')` and `function_exists('sodium_crypto_generichash')`,
     * and both answer out of the PHP build: identically on a deployment that
     * encrypts every field and on one that encrypts nothing. A fact that cannot
     * differ between those two deployments cannot be evidence about either, and
     * for as long as it was graded Measured it was the sole admissible proof under
     * nine controls across seven frameworks.
     *
     * WHAT THIS COST, and what closing it looked like. Two probes require this
     * fact, and for a while no other fact either of them required could reach
     * Measured: {@see \Pulsar\Compliance\Probe\CryptographicControlProbe} paired
     * it with a resolved MasterKey and
     * {@see \Pulsar\Compliance\Probe\DataProtectionAtRestProbe} with a Declared
     * session cipher, so five controls — GDPR Art 32, NIS2 Art 21(h), CCPA
     * 1798.150, GDPR Art 5(1)(f), NIST CSF PR.DS — could not reach Satisfied on
     * any deployment at all. A control that can never pass is the same class of
     * broken instrument as one that can never fail, so it was named here rather
     * than left for a reader to discover from a report that is always red.
     *
     * THREE OF THE FIVE ARE CLOSED and the other two are not, which is the shape
     * to keep. Both probes now also require
     * {@see ObservationId::PersonalDataFieldSealed}, which
     * {@see PersonalDataSealObserver} produces by handing the deployment a field
     * classified as personal data and reading back what it would store — so GDPR
     * Art 32, GDPR Art 5(1)(f) and CCPA 1798.150 reach Satisfied when that holds
     * and fail when it does not. NIS2 Art 21(h) regulates the cryptographic
     * PLATFORM, whose only fact is this one, and NIST CSF PR.DS regulates
     * confidential information; neither estate has been exercised by anything, so
     * both stay stuck and say so. The remedy is the one that worked here: an
     * observer for the estate, never a wider name for a fact that already exists.
     */
    private function cryptographicCapability(): Observation
    {
        return $this->platformCapability(
            'runtime.sodium_extension',
            ObservationId::CryptographicCapability,
            'the AEAD and hashing primitives libsodium provides',
            ['ext-sodium', 'sodium_crypto_generichash'],
            'libsodium was never checked, so nothing is established about what this platform '
                . 'offers.',
        );
    }

    /**
     * Whether the cryptography this deployment PERFORMS runs on a FIPS-validated
     * path — not whether the platform could offer one.
     *
     * The distinction is the whole content of the fact. {@see \Pulsar\Compliance\Verification\RuntimeVerifier}
     * reads the bound {@see \Pulsar\Security\Crypto\CipherSuiteInterface} and the
     * module executing it, so this observation is present only when approved
     * algorithms are the ones actually running. It graded true on every
     * mainstream OpenSSL before that, including the default deployment, which
     * encrypts with XChaCha20-Poly1305.
     *
     * Graded Resolved rather than Available, which is where it differs from the
     * fact above: what decides it is which suite this deployment bound.
     * {@see boundModuleResolution()} carries the argument in full.
     */
    private function fipsValidatedCryptography(): Observation
    {
        return $this->boundModuleResolution(
            'runtime.fips_mode',
            ObservationId::FipsValidatedCryptography,
            'the cipher suite this deployment bound and the module that executes it',
            'The FIPS check did not run for this profile, so nothing was established about '
                . 'the cryptography the deployment performs.',
        );
    }

    /**
     * Whether the key hierarchy this deployment encrypts under actually derives.
     *
     * THE ONE RUNTIME CHECK THAT KEEPS GRADE MEASURED, and it earns it by taking
     * the key that is in service and running it. `runtime.master_key_derived` calls
     * `sodium_crypto_kdf_derive_from_key` against that key and asserts three
     * properties of what came back: the requested length, reproducibility for the
     * same (subkey id, context), and different material for a different context.
     * That last one is the domain separation ADR-0006 has every subsystem relying
     * on. Read it against the two above: the sodium check would answer the same on
     * a deployment holding no key at all, and the FIPS check would answer the same
     * whether or not any key ever derived — this one cannot answer at all without
     * a key, and answers differently for a key that is broken. ISO 27001 A.8.24
     * says "including cryptographic key management" and this is the half of that
     * sentence software can answer.
     *
     * It is also why the old `fromRuntimeCheck()` had to be split per call site
     * rather than regraded in one edit: a blanket demotion would have thrown away
     * the one legitimate measurement in the group.
     */
    private function keyDerivation(): Observation
    {
        return $this->runtimeMeasurement(
            'runtime.master_key_derived',
            ObservationId::KeyDerivationVerified,
            'Key derivation was never exercised, so nothing is established about the key '
                . 'hierarchy this deployment encrypts under.',
        );
    }

    /**
     * Ask the evidence register what it is, and report the answer verbatim.
     *
     * The only fact in the whole set proved by cryptography rather than by
     * inspection. What it establishes is now what it says it establishes:
     * modification, reordering and injection by recomputing signatures, removal —
     * from the end as well as the middle — by comparing the register against the
     * height its anchor attests. Removal from the end is the one a hash chain
     * cannot see on its own, and this observation used to report a truncated
     * register as intact because it asked only the links.
     *
     * The reading is the chain's, not this class's. Handing a pre-read list of
     * records to a verifier is how a register the store was reporting as
     * unreadable came to be certified from whatever happened to decode; the
     * gatherer now asks {@see EvidenceChain::verify()} and grades the verdict.
     */
    private function auditChain(): Observation
    {
        if ($this->evidenceChain === null) {
            return Observation::measured(
                ObservationId::AuditChainVerified,
                Measurement::couldNotRun(
                    self::AUDIT_CHAIN_SUBJECT,
                    'No evidence chain is wired, so no audit record could be re-verified. '
                        . 'Tamper-evidence is claimed by nothing.',
                ),
                self::class,
            );
        }

        try {
            $result = $this->evidenceChain->verify($this->chainVerificationLimit);
        } catch (Throwable $error) {
            return Observation::measured(
                ObservationId::AuditChainVerified,
                Measurement::couldNotRun(
                    self::AUDIT_CHAIN_SUBJECT,
                    sprintf('Chain verification could not complete: %s', $error->getMessage()),
                ),
                self::class,
            );
        }

        $detail = sprintf(
            'The compliance evidence register is %s: %s (%d record(s) present, %s attested by '
                . 'the anchor, %d signature(s) recomputed, %d held).',
            self::CHAIN_VERDICT_WORDS[$result->verdict->name],
            $result->summary,
            $result->present,
            $result->attested === null ? 'none' : (string) $result->attested,
            $result->examined,
            $result->verified,
        );

        // Empty and Unanchored are not findings ABOUT the register; they are
        // reasons the measurement could not be made. An empty register has nothing
        // to verify, and a store that cannot attest its height cannot have its
        // completeness checked at all. Grading either as a pass would be the
        // ADR-0041 defect — a control satisfied by the absence of a measurement.
        if (
            $result->verdict === EvidenceChainVerdict::Empty
            || $result->verdict === EvidenceChainVerdict::Unanchored
        ) {
            return Observation::measured(
                ObservationId::AuditChainVerified,
                Measurement::couldNotRun(self::AUDIT_CHAIN_SUBJECT, $detail),
                self::class,
            );
        }

        return Observation::measured(
            ObservationId::AuditChainVerified,
            Measurement::completed(
                self::AUDIT_CHAIN_SUBJECT,
                [
                    $result->admissible()
                        ? ExecutedSubject::passed(self::AUDIT_CHAIN_SUBJECT, $detail)
                        : ExecutedSubject::failed(self::AUDIT_CHAIN_SUBJECT, $detail),
                ],
                $detail,
            ),
            self::class,
        );
    }

    /**
     * Execute the registered health checks.
     *
     * Executed, not counted. A monitoring or detection control that rested on the
     * mere existence of a check would be the ADR-0041 defect wearing a different
     * hat — which is exactly what ISO 42001 Clause 9.1 used to do, resting on an
     * interface with no implementations.
     */
    private function healthChecks(): Observation
    {
        if ($this->health === null) {
            return Observation::measured(
                ObservationId::HealthChecksExecuted,
                Measurement::couldNotRun(
                    self::HEALTH_CHECK_SUBJECT,
                    'No health-check runner is wired, so nothing in this deployment is monitored.',
                ),
                self::class,
            );
        }

        try {
            $report = $this->health->runAll();
        } catch (Throwable $error) {
            return Observation::measured(
                ObservationId::HealthChecksExecuted,
                Measurement::couldNotRun(
                    self::HEALTH_CHECK_SUBJECT,
                    sprintf('The health checks could not be executed: %s', $error->getMessage()),
                ),
                self::class,
            );
        }

        if ($report->results === []) {
            return Observation::measured(
                ObservationId::HealthChecksExecuted,
                Measurement::couldNotRun(
                    self::HEALTH_CHECK_SUBJECT,
                    'A health-check runner is wired but no check is registered, so executing '
                        . 'them observed nothing.',
                ),
                self::class,
            );
        }

        $executed = [];
        $unhealthy = [];

        foreach ($report->results as $result) {
            $line = sprintf('%s: %s', $result->name, $result->message);

            if ($result->status === HealthStatus::Healthy) {
                $executed[] = ExecutedSubject::passed($result->name, $line);

                continue;
            }

            $unhealthy[] = $line;
            $executed[] = ExecutedSubject::failed($result->name, $line);
        }

        return Observation::measured(
            ObservationId::HealthChecksExecuted,
            Measurement::completed(
                self::HEALTH_CHECK_SUBJECT,
                $executed,
                $unhealthy === []
                    ? sprintf(
                        'Executed %d registered health checks; all reported healthy: %s.',
                        count($report->results),
                        implode(', ', array_map(
                            static fn(HealthCheckResult $r): string => $r->name,
                            $report->results,
                        )),
                    )
                    : sprintf(
                        'Executed %d registered health checks; %d did not report healthy — %s.',
                        count($report->results),
                        count($unhealthy),
                        implode(' | ', $unhealthy),
                    ),
            ),
            self::class,
        );
    }

    // -- Declared configuration ----------------------------------------------

    /**
     * One security-posture item, reported as the configuration read it is.
     *
     * There is no per-item branch left here. There was one — `master_key` reached
     * {@see Observation::measured()} and every other item reached
     * {@see Observation::declaredMet()} — and it is gone for the reason
     * {@see POSTURE_FACTS} gives: reading a value out of the environment instead
     * of out of a file establishes nothing more about what the deployment did with
     * it. One grade for the whole family also means the method can no longer drift
     * item by item.
     */
    private function fromPosture(string $item, ObservationId $id): Observation
    {
        foreach ($this->posture->items as $posture) {
            if ($posture->name !== $item) {
                continue;
            }

            $detail = $posture->reason !== ''
                ? $posture->reason
                : sprintf('The posture item "%s" reported status %s and gave no reason.', $item, $posture->status->value);

            // `Ok` alone is not "the control holds". The posture check answers
            // "should this deployment be stopped over it?", and outside production
            // it answers no for weaknesses that plainly exist: this mapping used to
            // publish `debug_mode_disabled / present: true` next to the reason
            // "Debug mode is enabled (expected outside production)". A relaxed item
            // is an item whose control does NOT hold; see
            // {@see \Pulsar\Security\Posture\SecurityPostureItem::relaxed()}.
            $ok = $posture->status === SecurityPostureStatus::Ok && !$posture->relaxed;

            return $ok
                ? Observation::declaredMet($id, $detail, SecurityPostureReport::class)
                : Observation::declaredUnmet($id, $detail, SecurityPostureReport::class);
        }

        return Observation::declaredUnmet(
            $id,
            sprintf('The security posture preflight produced no "%s" item.', $item),
            self::class,
        );
    }

    /**
     * Cookie hardening as one fact: a Secure cookie that is not HttpOnly is not
     * "two thirds protected", it is readable by script.
     */
    private function cookieHardening(): Observation
    {
        $weak = [];
        $seen = 0;

        foreach ($this->posture->items as $item) {
            if (!in_array($item->name, self::COOKIE_POSTURE_ITEMS, true)) {
                continue;
            }

            ++$seen;

            // Relaxed counts as weak here for the same reason it does in
            // {@see fromPosture()}: a cookie without the Secure flag is readable off
            // the wire whether or not the environment warrants failing the build.
            if ($item->status !== SecurityPostureStatus::Ok || $item->relaxed) {
                $weak[] = sprintf('%s: %s', $item->name, $item->reason);
            }
        }

        // A preflight that produced no cookie items at all did not find them
        // acceptable; it never looked. Saying otherwise would report a pass from
        // the absence of a measurement, which is the failure mode in miniature.
        if ($seen !== count(self::COOKIE_POSTURE_ITEMS)) {
            return Observation::declaredUnmet(
                ObservationId::SessionCookiesHardened,
                sprintf(
                    'The security posture preflight produced %d of the %d session-cookie items, '
                        . 'so cookie hardening was never established.',
                    $seen,
                    count(self::COOKIE_POSTURE_ITEMS),
                ),
                self::class,
            );
        }

        return $weak === []
            ? Observation::declaredMet(
                ObservationId::SessionCookiesHardened,
                'Session cookies are configured Secure, HttpOnly and with a SameSite policy.',
                SecurityPostureReport::class,
            )
            : Observation::declaredUnmet(
                ObservationId::SessionCookiesHardened,
                implode(' | ', $weak),
                SecurityPostureReport::class,
            );
    }

    private function retentionBounded(): Observation
    {
        if ($this->profile === null) {
            return $this->noProfile(ObservationId::RetentionBounded, 'retention period');
        }

        $bounded = $this->profile->auditRetentionDays > 0 && $this->profile->dataRetentionDays > 0;
        $detail = sprintf(
            'Profile retention: audit %d day(s), data %d day(s).',
            $this->profile->auditRetentionDays,
            $this->profile->dataRetentionDays,
        );

        return $bounded
            ? Observation::declaredMet(ObservationId::RetentionBounded, $detail, ComplianceProfile::class)
            : Observation::declaredUnmet(ObservationId::RetentionBounded, $detail, ComplianceProfile::class);
    }

    private function breachDeadline(): Observation
    {
        if ($this->profile === null) {
            return $this->noProfile(ObservationId::BreachNotificationDeadlineSet, 'breach notification deadline');
        }

        $detail = sprintf(
            'Profile breach notification deadline: %d hour(s); individual notification %s, '
                . 'breach register %s.',
            $this->profile->breachNotificationHours,
            $this->profile->individualNotification ? 'required' : 'not required',
            $this->profile->breachRegister ? 'required' : 'not required',
        );

        return $this->profile->breachNotificationHours > 0
            ? Observation::declaredMet(
                ObservationId::BreachNotificationDeadlineSet,
                $detail,
                ComplianceProfile::class,
            )
            : Observation::declaredUnmet(
                ObservationId::BreachNotificationDeadlineSet,
                $detail,
                ComplianceProfile::class,
            );
    }

    private function passwordPolicy(): Observation
    {
        if ($this->profile === null) {
            return $this->noProfile(ObservationId::PasswordPolicyEnforced, 'password policy');
        }

        $detail = sprintf(
            'Profile minimum password length: %d character(s); session idle timeout %d second(s).',
            $this->profile->passwordMinLength,
            $this->profile->sessionIdleTimeout,
        );

        return $this->profile->passwordMinLength >= 12
            ? Observation::declaredMet(ObservationId::PasswordPolicyEnforced, $detail, ComplianceProfile::class)
            : Observation::declaredUnmet(ObservationId::PasswordPolicyEnforced, $detail, ComplianceProfile::class);
    }

    private function mfaPolicy(): Observation
    {
        if ($this->profile === null) {
            return $this->noProfile(ObservationId::MfaPolicyRequired, 'multi-factor requirement');
        }

        $detail = sprintf('Profile MFA scope: %s.', $this->profile->mfaRequirement);

        return $this->profile->requiresMfa()
            ? Observation::declaredMet(ObservationId::MfaPolicyRequired, $detail, ComplianceProfile::class)
            : Observation::declaredUnmet(ObservationId::MfaPolicyRequired, $detail, ComplianceProfile::class);
    }

    private function noProfile(ObservationId $id, string $subject): Observation
    {
        return Observation::declaredUnmet(
            $id,
            sprintf(
                'No ComplianceProfile was resolved, so no %s was derived from any framework.',
                $subject,
            ),
            self::class,
        );
    }

    // -- Shared adapters ------------------------------------------------------

    /**
     * What one of {@see \Pulsar\Compliance\Verification\RuntimeVerifier}'s checks
     * reported, in the four states the three adapters below have to tell apart.
     *
     * The verifier is reused whole rather than reimplemented: it is the single
     * implementation of these facts in the tree and simply acquires a consumer.
     * A NULL STATUS IS THE FOURTH STATE and is not a pass: it means the check is
     * not in this run's results at all, which is a different fact from Skip and a
     * very different one from Fail. A skipped check is likewise not a pass, and the
     * sentence says so before the verifier's own message, because the two read
     * identically in a summary and only one of them is evidence.
     *
     * Returns a shaped array rather than the {@see CheckResult} itself, which reads
     * as a detour and is not one: handing back the result would make this method a
     * seam typed on a final class that no consumer can decorate, and the substitutability
     * gate refuses that. What the callers actually need is the status and the
     * sentence, and deriving the sentence here is what keeps the four states worded
     * the same way under all three grades.
     *
     * @param non-empty-string $whenMissing What to say when the check is not in the results
     *
     * @return array{status: ?CheckStatus, detail: non-empty-string}
     */
    #[NoDiscard]
    private function runtimeReading(string $checkId, string $whenMissing): array
    {
        foreach ($this->runtimeChecks as $check) {
            if ($check->checkId !== $checkId) {
                continue;
            }

            return [
                'status' => $check->status,
                'detail' => match (true) {
                    $check->status === CheckStatus::Skip => sprintf(
                        'Not established — the check was skipped: %s',
                        $check->message,
                    ),
                    $check->message !== '' => $check->message,
                    default => sprintf(
                        'Check %s reported %s with no message.',
                        $checkId,
                        $check->status->value,
                    ),
                },
            ];
        }

        return ['status' => null, 'detail' => $whenMissing];
    }

    /**
     * A runtime check that asked the PLATFORM what it has.
     *
     * The first of the three adapters this method used to be. It was a single
     * `fromRuntimeCheck()` wrapping every runtime check in
     * {@see Observation::measured()}, on the argument that a check which executes
     * is a measurement — and that argument is wrong twice over. It is wrong about
     * what "runs" means: `extension_loaded('sodium')` runs, and answers about the
     * build PHP was compiled with rather than about anything this deployment did.
     * And it is wrong about the consequence: `runtime.sodium_extension` at grade
     * Measured was admissible proof, and it carried nine controls across seven
     * frameworks — CCPA 1798.150, GDPR Art 5(1)(f) and Art 32, HIPAA
     * 164.312(a)(2)(iv) and its 2026 twin, ISO 27001 A.8.24, NIS2 Art 21(h), NIST
     * CSF PR.DS and PCI DSS Req 3.4 — to Satisfied on a loaded extension.
     *
     * A blanket regrade of the old method would have been the mirror mistake:
     * `runtime.master_key_derived` genuinely exercises this deployment's own key,
     * and its Measured grade is earned. So the split is per call site, and each
     * site states which of the three kinds of thing its check touched.
     *
     * @param non-empty-string $capability  The primitive family, for the report
     * @param list<string>     $primitives  What a passing check proves is present, named one
     *        by one: {@see PlatformCapability} cannot be built claiming a capability and
     *        naming nothing, for the reason a {@see Measurement} cannot name nothing that ran
     * @param non-empty-string $whenMissing
     */
    #[NoDiscard]
    private function platformCapability(
        string $checkId,
        ObservationId $id,
        string $capability,
        array $primitives,
        string $whenMissing,
    ): Observation {
        ['status' => $status, 'detail' => $detail] = $this->runtimeReading($checkId, $whenMissing);

        return Observation::available(
            $id,
            match ($status) {
                CheckStatus::Pass => PlatformCapability::offered($capability, $primitives, $detail),
                CheckStatus::Fail => PlatformCapability::absent($capability, $detail),
                CheckStatus::Skip, null => PlatformCapability::notInspected($capability, $detail),
            },
            self::class,
        );
    }

    /**
     * A runtime check whose answer is decided by WHAT THIS DEPLOYMENT BOUND.
     *
     * The second adapter, and the one where the brief that ordered this work and a
     * design review disagreed. The brief said to grade `runtime.fips_mode`
     * {@see \Pulsar\Compliance\Control\ObservationGrade::Available} beside the
     * sodium check; review said FIPS 140 validation is a property of a module
     * build rather than an exercisable behaviour, and that resolution of the bound
     * suite is its honest ceiling. Review is right, and the deciding argument is
     * the one that justifies the Available case existing at all — what the word
     * tells the reader.
     *
     * Follow what the check reads. {@see \Pulsar\Compliance\Verification\RuntimeVerifier::verify()}
     * asks which {@see \Pulsar\Security\Crypto\CipherSuiteInterface} is bound and
     * whether that suite is on an approved list; that is a resolution, and it is
     * the only input that can produce a pass. The platform introspection beside it
     * — the OpenSSL provider, the version banner, whether the suite executes
     * through libsodium — can only ever take a pass away. So a present observation
     * here means "this deployment bound an approved suite and the module running
     * it is validated", and printing `(available)` beside that would tell an
     * assessor FIPS was on offer and unused when in fact it is in use.
     *
     * The material is an {@see Inspection} rather than a {@see ContractResolution}
     * because the verifier hands back its own finding, not a class name this class
     * could look up: a pass is a scan that found nothing wrong with the bound
     * suite, a failure is that scan naming what it found, and a skip is the scan
     * not having happened. Presence is derived from the finding in every branch
     * and is never passed in. Note the one seam that is imprecise and is left
     * imprecise rather than papered over: {@see CheckStatus::Skip} covers both
     * "the profile requires no encryption, so nobody looked" and "the bound suite
     * is not approved, so FIPS is not established", which are an absence and a
     * finding. Both observe absent and both reproduce the verifier's own sentence
     * verbatim, so no reader is misled; separating them means splitting the status
     * enum, which is not this change.
     *
     * @param non-empty-string $population
     * @param non-empty-string $whenMissing
     */
    #[NoDiscard]
    private function boundModuleResolution(
        string $checkId,
        ObservationId $id,
        string $population,
        string $whenMissing,
    ): Observation {
        ['status' => $status, 'detail' => $detail] = $this->runtimeReading($checkId, $whenMissing);

        return Observation::inspected(
            $id,
            match ($status) {
                CheckStatus::Pass => Inspection::defectScan($population, [], $detail),
                CheckStatus::Fail => Inspection::defectScan($population, [$detail], $detail),
                CheckStatus::Skip, null => Inspection::nothingToInspect($population, $detail),
            },
            self::class,
        );
    }

    /**
     * A runtime check that put this deployment through the work and read what came
     * back.
     *
     * The third adapter, and the one that keeps {@see \Pulsar\Compliance\Control\ObservationGrade::Measured}.
     * A skipped check, and one absent from the results altogether, are both
     * reported as a run that did not happen rather than as one that returned
     * nothing: the two read the same in a summary and only one of them is evidence.
     *
     * @param non-empty-string $whenMissing
     */
    #[NoDiscard]
    private function runtimeMeasurement(string $checkId, ObservationId $id, string $whenMissing): Observation
    {
        ['status' => $status, 'detail' => $detail] = $this->runtimeReading($checkId, $whenMissing);

        return Observation::measured(
            $id,
            match ($status) {
                CheckStatus::Pass => Measurement::completed(
                    $checkId,
                    [ExecutedSubject::passed($checkId, $detail)],
                    $detail,
                ),
                CheckStatus::Fail => Measurement::completed(
                    $checkId,
                    [ExecutedSubject::failed($checkId, $detail)],
                    $detail,
                ),
                CheckStatus::Skip, null => Measurement::couldNotRun($checkId, $detail),
            },
            self::class,
        );
    }
}
