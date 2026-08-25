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
use Pulsar\Compliance\Verification\CheckResult;
use Pulsar\Compliance\Verification\CheckStatus;
use Pulsar\Compliance\Verification\DataPathVerifier;
use Pulsar\Compliance\Verification\EvidenceChain;
use Pulsar\Compliance\Verification\EvidenceChainVerdict;
use Pulsar\Config\DatabaseConfig;
use Pulsar\Core\Wiring\Contract\DegradedFeature;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Resilience\HealthCheck\HealthCheckResult;
use Pulsar\Resilience\HealthCheck\HealthCheckRunnerInterface;
use Pulsar\Resilience\HealthCheck\HealthStatus;
use Pulsar\Security\Crypto\TokenizationServiceInterface;
use Pulsar\Security\Crypto\TokenStoreInterface;
use Pulsar\Security\Posture\SecurityPostureReport;
use Pulsar\Security\Posture\SecurityPostureStatus;
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
 * the page.
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
 * GATHERING HAS SIDE EFFECTS, and one of them writes. It opens a database
 * session and queries it, it EXECUTES every registered health check, it
 * recomputes one HMAC per stored evidence record, and — through
 * {@see TokenVaultObserver} — it tokenizes one synthetic value, reads it back,
 * detokenizes it and removes it again. That last one is the only write, and it
 * is what separates a token vault that works from one that merely resolves; see
 * that class for what it writes and why nothing weaker would do. None of this
 * belongs in a request boot, which is the other half of why the binding is lazy.
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
            'accepts' => [],
            'inert' => [],
        ],
        ObservationId::AiModelRegistryResolved->value => [
            'contract' => 'Pulsar\Extension\AiGovernance\Contracts\AiModelRegistryInterface',
            'role' => 'records the AI models in service and their lifecycle state',
            'accepts' => [],
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
            'accepts' => [],
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
            'accepts' => [],
            'inert' => [
                'Pulsar\Extension\AiGovernance\Internal\Store\InMemoryDataGovernanceStore' =>
                    'a development store; provenance and quality reports are held in process memory '
                    . 'and lost on restart',
                'Pulsar\Extension\AiGovernance\Internal\ConsentEnforcingDataGovernance' =>
                    'a decorator that rejects provenance recorded without consent — a real guarantee, '
                    . 'but one about the boundary and not about the record. What it wraps is invisible '
                    . 'from here, and what it wraps in this release is the in-memory store',
            ],
        ],
        ObservationId::AiExplainabilityResolved->value => [
            'contract' => 'Pulsar\Extension\AiGovernance\Contracts\ExplainabilityInterface',
            'role' => 'explains a model decision to the person it affected',
            'accepts' => [],
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
            'accepts' => [],
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
     * All Declared but one: these read `SecurityConfig`, so on their own they can
     * never carry a control to Satisfied. The exception is `master_key`, which
     * {@see \Pulsar\Security\Posture\SecurityPostureCheck} reads from the process
     * environment rather than from a config file — that is a property of the
     * running process, so it grades Measured.
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
        private ?DataPathVerifier $dataPaths = null,
        private ?ComplianceProfile $profile = null,
        private ?DatabaseConfig $databaseConfig = null,
        private ?ConnectionInterface $connection = null,
        private ?EvidenceChain $evidenceChain = null,
        private ?HealthCheckRunnerInterface $health = null,
        private ?TokenizationServiceInterface $tokenizer = null,
        private ?TokenStoreInterface $tokenStore = null,
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
        $observations[] = $this->cookieHardening();
        $observations[] = $this->complianceProfile();
        $observations[] = $this->securityFeaturesIntact();
        $observations[] = $this->dataPaths !== null
            ? $this->routes->observe($this->dataPaths)
            : $this->routeCoverageUndetermined();
        $observations[] = $this->databaseTls->observe($this->databaseConfig, $this->connection);
        $observations[] = $this->tokenVault->observe($this->tokenizer, $this->tokenStore);
        $observations[] = $this->aiGovernanceExtension();
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
     * by naming the interface instead: there is no interface. Nor can it be
     * measured from here — exercising the cipher means holding one, and
     * `SessionEncryption` is #[Internal] to the Security module, so importing it
     * would break the boundary the composition root exists to keep. What CAN be
     * said honestly is what the deployment asked for, and that is what this says.
     *
     * The binding is not nothing: SecurityWiring constructs it only when
     * `security.session.encryption` is true AND the master key loaded, so its
     * absence is a real gap and is reported as one. But it records an intention
     * that was acted on, not a payload observed encrypted, and Declared is the
     * grade for that. The controls resting on it now reach "configured and
     * unobserved" instead of Satisfied, which is the true state of affairs.
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

    // -- Measured behaviour ---------------------------------------------------

    /**
     * Whether the cryptography the framework depends on is actually available.
     *
     * Measured: {@see \Pulsar\Compliance\Verification\RuntimeVerifier} calls
     * `extension_loaded('sodium')` and `function_exists('sodium_crypto_generichash')`
     * — the algorithms answer for themselves rather than a setting answering for them.
     */
    private function cryptographicCapability(): Observation
    {
        return $this->fromRuntimeCheck(
            'runtime.sodium_extension',
            ObservationId::CryptographicCapability,
            'libsodium was never checked, so no cryptographic capability was established.',
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
     */
    private function fipsValidatedCryptography(): Observation
    {
        return $this->fromRuntimeCheck(
            'runtime.fips_mode',
            ObservationId::FipsValidatedCryptography,
            'The FIPS check did not run for this profile, so nothing was established about '
                . 'the cryptography the deployment performs.',
        );
    }

    /**
     * Whether the key hierarchy this deployment encrypts under actually derives.
     *
     * `runtime.master_key_derived` runs `sodium_crypto_kdf_derive_from_key`
     * against the key in service and asserts three properties of what came back:
     * the requested length, reproducibility for the same (subkey id, context),
     * and different material for a different context. That last one is the
     * domain separation ADR-0006 has every subsystem relying on, and it is the
     * only key-management property in the whole evidence set that is established
     * by running the KDF rather than by observing that a `MasterKey` object
     * exists. ISO 27001 A.8.24 says "including cryptographic key management" and
     * this is the half of that sentence software can answer.
     */
    private function keyDerivation(): Observation
    {
        return $this->fromRuntimeCheck(
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

    private function fromPosture(string $item, ObservationId $id): Observation
    {
        // master_key is read from the process environment, not from a config file,
        // so it is a property of the running process rather than a request. It is
        // the one posture item that reaches a measurement rather than a config read.
        $measured = $item === 'master_key';

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

            if ($measured) {
                return Observation::measured(
                    $id,
                    Measurement::completed(
                        $item,
                        [$ok
                            ? ExecutedSubject::passed($item, $detail)
                            : ExecutedSubject::failed($item, $detail)],
                        $detail,
                    ),
                    SecurityPostureReport::class,
                );
            }

            return $ok
                ? Observation::declaredMet($id, $detail, SecurityPostureReport::class)
                : Observation::declaredUnmet($id, $detail, SecurityPostureReport::class);
        }

        $absent = sprintf('The security posture preflight produced no "%s" item.', $item);

        return $measured
            ? Observation::measured($id, Measurement::couldNotRun($item, $absent), self::class)
            : Observation::declaredUnmet($id, $absent, self::class);
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
     * Adapt one of {@see \Pulsar\Compliance\Verification\RuntimeVerifier}'s
     * results, keyed by the check id it already publishes.
     *
     * The verifier is reused whole rather than reimplemented: it is the single
     * implementation of these facts in the tree and simply acquires a consumer.
     * A skipped check is NOT a pass — it means the question was never asked.
     */
    /**
     * @param non-empty-string $whenMissing
     */
    private function fromRuntimeCheck(string $checkId, ObservationId $id, string $whenMissing): Observation
    {
        foreach ($this->runtimeChecks as $check) {
            if ($check->checkId !== $checkId) {
                continue;
            }

            $detail = match (true) {
                $check->status === CheckStatus::Skip => sprintf(
                    'Not established — the check was skipped: %s',
                    $check->message,
                ),
                $check->message !== '' => $check->message,
                default => sprintf('Check %s reported %s with no message.', $checkId, $check->status->value),
            };

            // A skipped check did not run, so it is reported as a run that did not
            // happen rather than as one that returned nothing: the two read the same
            // in a summary and only one of them is evidence.
            return Observation::measured(
                $id,
                $check->status === CheckStatus::Skip
                    ? Measurement::couldNotRun($checkId, $detail)
                    : Measurement::completed(
                        $checkId,
                        [$check->status === CheckStatus::Pass
                            ? ExecutedSubject::passed($checkId, $detail)
                            : ExecutedSubject::failed($checkId, $detail)],
                        $detail,
                    ),
                self::class,
            );
        }

        return Observation::measured($id, Measurement::couldNotRun($checkId, $whenMissing), self::class);
    }
}
