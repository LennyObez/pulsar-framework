<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Support;

use DateTimeImmutable;
use Override;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\ComplianceProfile;
use Pulsar\Compliance\ComplianceProfileResolver;
use Pulsar\Compliance\Control\ControlAssessment;
use Pulsar\Compliance\Control\ControlEvidence;
use Pulsar\Compliance\Control\ControlFinding;
use Pulsar\Compliance\Evidence\AiTransparencyDrillInterface;
use Pulsar\Compliance\Evidence\ControlEvidenceGatherer;
use Pulsar\Compliance\Evidence\EvidenceStoreInterface;
use Pulsar\Compliance\Evidence\InMemoryEvidenceStore;
use Pulsar\Compliance\Evidence\RouteInventory;
use Pulsar\Compliance\Verification\EvidenceChain;
use Pulsar\Compliance\Verification\VerificationReport;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\Container;
use Pulsar\Container\ContainerInterface;
use Pulsar\Core\Wiring\ComplianceCatalogWiring;
use Pulsar\Core\Wiring\ConfigLoaderRegistrar;
use Pulsar\Core\Wiring\WiringList;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Dialect\DialectInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\DriverVariant;
use Pulsar\Database\PdoConnection;
use Pulsar\Database\Result;
use Pulsar\Database\Statement;
use Pulsar\Database\Transaction;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\ExtensionManifest;
use Pulsar\Extensibility\ExtensionRegistry;
use Pulsar\Extension\AiGovernance\Internal\Compliance\AiTransparencyDrill;
use Pulsar\Extension\AiGovernance\Internal\Store\InMemoryAiTransparency;
use Pulsar\Http\Method;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Resilience\HealthCheck\HealthCheckInterface;
use Pulsar\Resilience\HealthCheck\HealthCheckResult;
use Pulsar\Resilience\HealthCheck\HealthCheckRunner;
use Pulsar\Resilience\HealthCheck\HealthCheckRunnerInterface;
use Pulsar\Resilience\HealthCheck\HealthStatus;
use Pulsar\Routing\Route;
use Pulsar\Routing\Router;
use Pulsar\Routing\RouterInterface;
use Pulsar\Security\Audit\AuditFileSink;
use Pulsar\Security\Audit\AuditLogger;
use Pulsar\Security\Compliance\Pseudonymization\FilePseudonymLookup;
use Pulsar\Security\Compliance\Pseudonymization\ForgetService;
use Pulsar\Security\Compliance\Pseudonymization\ForgetServiceInterface;
use Pulsar\Security\Compliance\Pseudonymization\InMemoryPseudonymLookup;
use Pulsar\Security\Compliance\Pseudonymization\PseudonymizationService;
use Pulsar\Security\Compliance\Pseudonymization\PseudonymizationServiceInterface;
use Pulsar\Security\Compliance\Pseudonymization\PseudonymLookupInterface;
use Pulsar\Security\Crypto\DatabaseTokenStore;
use Pulsar\Security\Crypto\Encryptor;
use Pulsar\Security\Crypto\EncryptorInterface;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Security\Crypto\TokenizationService;
use Pulsar\Security\Incident\FileIncidentReporter;
use Pulsar\Security\Incident\IncidentReporterInterface;
use Pulsar\Security\Incident\InMemoryIncidentReporter;
use Pulsar\Security\Posture\SecurityPostureItem;
use Pulsar\Security\Posture\SecurityPostureReport;
use Pulsar\Security\Session\SessionEncryption;
use Pulsar\Security\Session\SessionPayloadCipherInterface;
use Pulsar\Tests\Support\Compliance\UnauthenticatedFieldEncryptor;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;
use stdClass;

use function array_map;
use function base64_decode;
use function base64_encode;
use function bin2hex;
use function file_put_contents;
use function implode;
use function mkdir;
use function random_bytes;
use function sprintf;
use function str_contains;
use function sys_get_temp_dir;
use function var_export;

/**
 * A deployment built for a test to assess.
 *
 * The point of this fixture — and of every test that uses it — is that a control's
 * outcome is never written anywhere. A test says what the deployment HAS, the real
 * {@see ComplianceCatalogWiring} resolves it, the real
 * {@see ControlEvidenceGatherer} observes it, and the real probes conclude. The
 * assertion is on the conclusion.
 *
 * That is deliberately the opposite of the tests this replaces, which read a
 * literal out of a mapping file and asserted the literal back — a test that could
 * only fail if someone edited the file it was reading, and which stayed green
 * through the entire life of the ADR-0041 defect.
 *
 * Contracts are satisfied by binding an instance of the concrete class whose
 * identity the gatherer records. Several of those classes have expensive
 * constructors and none of their internal state is under test here — what IS under
 * test is which class answered — so {@see resolving()} builds them without running
 * the constructor, deliberately and only for that reason.
 */
final class DeploymentUnderAssessment
{
    /** @var array<string, object> */
    private array $bindings = [];

    /** @var list<string> */
    private array $extensions = [];

    /** @var array<string, bool> */
    private array $scope = [];

    /** @var list<HealthCheckInterface> */
    private array $healthChecks = [];

    /** @var list<ComplianceFramework> */
    private readonly array $frameworks;

    /** @var list<Route> */
    private array $routes = [];

    private bool $withChain = false;

    private bool $networkedDatabase = false;

    private bool $withProfile = true;

    private ?string $stateDirectory = null;

    private ?ControlEvidence $gathered = null;

    private ?ControlAssessment $assessment = null;

    /**
     * @param list<ComplianceFramework> $frameworks
     */
    private function __construct(array $frameworks)
    {
        $this->frameworks = $frameworks;
    }

    /**
     * A deployment that has nothing: no vault, no audit sink, no extensions.
     *
     * This is the baseline every "absent" assertion runs against, and it is close
     * to what a freshly installed Pulsar actually is.
     *
     * @param list<ComplianceFramework> $frameworks
     */
    public static function withNothing(array $frameworks): self
    {
        return new self($frameworks);
    }

    /**
     * A deployment carrying every implementation this release assesses.
     *
     * Everything a probe could ask for is present EXCEPT the three facts nothing
     * in the tree can supply — a backup primitive, a data-subject request store,
     * and an AI monitoring hook. That is not an oversight in the fixture: those
     * contracts have no implementation anywhere in the repository, which is why
     * the controls that need them stay red no matter how the deployment is built.
     *
     * @param list<ComplianceFramework> $frameworks
     */
    public static function fullyEquipped(array $frameworks): self
    {
        return self::withNothing($frameworks)
            ->withWorkingTokenVault()
            ->resolving('Pulsar\Security\Audit\AuditSinkInterface', 'Pulsar\Security\Audit\AuditFileSink')
            ->withWorkingSessionSeal()
            ->withFieldEncryption()
            ->resolving('Pulsar\Auth\TwoFactor\TwoFactorManagerInterface', 'Pulsar\Auth\TwoFactor\TwoFactorManager')
            ->withWorkingIncidentRegister()
            ->resolving('Pulsar\DataProtection\DataPurgeInterface', 'Pulsar\DataProtection\DataPurgeOrchestrator')
            ->resolving('Pulsar\DataProtection\RetentionPolicyInterface', 'Pulsar\DataProtection\DefaultRetentionPolicy')
            ->withWorkingPseudonymization()
            ->resolving(
                'Pulsar\Observability\Tracing\SpanProcessorInterface',
                'Pulsar\Extension\OpenTelemetry\Bridge\OtlpTracerBridge',
            )
            ->resolving('Pulsar\DataProtection\ConsentManagerInterface', 'Pulsar\DataProtection\InMemoryConsentManager')
            ->withExtension(ControlEvidenceGatherer::AI_GOVERNANCE_EXTENSION)
            ->resolving(
                'Pulsar\Extension\AiGovernance\Contracts\AiModelRegistryInterface',
                'Pulsar\Extension\AiGovernance\Internal\Store\InMemoryModelRegistry',
            )
            ->resolving(
                'Pulsar\Extension\AiGovernance\Contracts\AiImpactAssessmentInterface',
                'Pulsar\Extension\AiGovernance\Internal\Store\InMemoryImpactAssessmentStore',
            )
            ->resolving(
                'Pulsar\Extension\AiGovernance\Contracts\AiAuditLoggerInterface',
                'Pulsar\Extension\AiGovernance\Internal\AiAuditLogger',
            )
            ->resolving(
                'Pulsar\Extension\AiGovernance\Contracts\AiDataGovernanceInterface',
                'Pulsar\Extension\AiGovernance\Internal\ConsentEnforcingDataGovernance',
            )
            ->resolving(
                'Pulsar\Extension\AiGovernance\Contracts\ExplainabilityInterface',
                'Pulsar\Extension\AiGovernance\Internal\Store\InMemoryExplainabilityStore',
            )
            ->resolving(
                'Pulsar\Extension\AiGovernance\Contracts\AiLifecycleManagerInterface',
                'Pulsar\Extension\AiGovernance\Internal\AiLifecycleManager',
            )
            ->resolving(
                'Pulsar\Extension\AiGovernance\Contracts\DeploymentGateInterface',
                'Pulsar\Extension\AiGovernance\Internal\Gate\ImpactAssessmentGate',
            )
            ->withArticle50Transparency()
            // The two optional bindings whose absence makes a security feature
            // inert; without them the wiring-contract detector vetoes every
            // control that requires nothing to be silently disabled.
            ->resolvingInstance('Pulsar\Cache\Application\TaggedCacheInterface', new stdClass())
            ->withVerifiedEvidenceChain()
            ->withHealthCheck(self::passingHealthCheck('database'))
            ->withClassifiedRoute();
    }

    /**
     * An Article 50 transparency subsystem that actually works.
     *
     * Built for real rather than {@see hollow()}, for the reason the token vault
     * is: {@see \Pulsar\Compliance\Evidence\AiTransparencyObserver} declares a
     * surface through the subsystem, reads the policy back and mints a mark, so an
     * object of the right class establishes nothing. Both the contract and the
     * compliance seam over it are bound from ONE store, which is what a booted
     * ai-governance extension produces — the drill must reach the same subsystem an
     * application's own surfaces reach, or the assessment measures a second copy
     * nobody uses.
     */
    public function withArticle50Transparency(): self
    {
        $store = new InMemoryAiTransparency();

        return $this
            ->resolvingInstance('Pulsar\Extension\AiGovernance\Contracts\AiTransparencyInterface', $store)
            ->resolvingInstance(AiTransparencyDrillInterface::class, new AiTransparencyDrill($store));
    }

    /**
     * A deployment whose transparency seam is whatever the test hands it.
     *
     * For the shapes a working subsystem cannot produce: one that forgets a
     * declaration, one that mints a mark for a surface nobody declared. The
     * assessment then runs unchanged over it, which is the only way to establish
     * that the control can reach the outcome it is supposed to.
     */
    public function withTransparencySeam(AiTransparencyDrillInterface $drill): self
    {
        return $this->resolvingInstance(AiTransparencyDrillInterface::class, $drill);
    }

    /**
     * A token vault that actually works, and a key hierarchy that actually derives.
     *
     * These three bindings are built for real rather than {@see hollow()} — as is
     * the session cipher, in {@see withWorkingSessionSeal()} — and the reason is
     * the whole of ADR-0041's second lesson. Every fact in the evidence set that
     * is about which class answered a contract needs an object of the right class
     * and nothing more; every fact that is a MEASUREMENT needs a subsystem that
     * can actually be put to work, and those are the ones built here. PAN-at-rest
     * is not: {@see \Pulsar\Compliance\Evidence\TokenVaultObserver} puts a value
     * through the vault and reads the persisted bytes back, and a
     * `DatabaseTokenStore` conjured without its constructor would throw on the
     * first query — correctly, because a store with no connection renders nothing
     * unreadable. So the fixture stands up an in-memory SQLite database, creates
     * the table the store writes to, and derives a real key. A test that wants to
     * claim the control now has to build a deployment that could actually hold a
     * PAN, which is the point.
     */
    public function withWorkingTokenVault(): self
    {
        return $this->withTokenVault(tableExists: true);
    }

    /**
     * The vault this repository actually had: the durable store, bound, over a
     * database with no `token_vault` table in it.
     *
     * Every identity fact reads clean — `TokenStoreInterface` resolves to
     * `DatabaseTokenStore`, which is the implementation ADR-0041 prescribed — and
     * the first tokenize() throws, so nothing is ever rendered unreadable. This
     * is the deployment that made "check which store resolved" insufficient, and
     * a test that cannot express it cannot guard against it.
     */
    public function withUnusableTokenVault(): self
    {
        return $this->withTokenVault(tableExists: false);
    }

    /**
     * A session cipher that actually seals, built from a real key.
     *
     * Built for real rather than {@see hollow()}, as the token vault's bindings
     * above are, and for the same reason:
     * {@see \Pulsar\Compliance\Evidence\SessionSealObserver} puts a payload
     * through the cipher, opens it, modifies a byte and offers it as another
     * session. A `SessionEncryption` conjured without its constructor holds an
     * empty key and a key ring that was never initialised, so it would throw on
     * the first seal — correctly, because a cipher with no key seals nothing. A
     * test that wants to claim SWIFT CSP 2.6 now has to build a deployment whose
     * sessions could genuinely be sealed, which is the point.
     *
     * The key is the one the deployment already holds when there is one, so the
     * fixture matches what SecurityWiring does — the session cipher is derived
     * from the master key, not from a key of its own. When there is none, a
     * throwaway key is derived and NOT bound: this method's job is to give the
     * deployment a working cipher, not to quietly give it a key hierarchy that
     * other facts would then read.
     */
    public function withWorkingSessionSeal(): self
    {
        $bound = $this->bindings['Pulsar\Security\Crypto\MasterKey'] ?? null;
        $masterKey = $bound instanceof MasterKey ? $bound : MasterKey::fromHex(bin2hex(random_bytes(32)));

        return $this->resolvingInstance(
            'Pulsar\Security\Session\SessionEncryption',
            SessionEncryption::fromMasterKey($masterKey),
        );
    }

    /**
     * The encryptor every field this deployment classifies at rest is sealed with.
     *
     * Built for real, like the vault, the session cipher and the pseudonymisation
     * services, and for the same reason: {@see \Pulsar\Compliance\Evidence\PersonalDataSealObserver}
     * puts a field classified as personal data through
     * {@see \Pulsar\Workflow\Storage\ClassifiedContext} and reads the at-rest form
     * back, so an object of the right class establishes nothing at all. An
     * `Encryptor` conjured without its constructor holds no derived key and would
     * throw on the first seal — correctly, because a cipher with no key seals
     * nothing.
     *
     * The key is the one the deployment already holds when there is one, so the
     * fixture matches {@see \Pulsar\Core\Wiring\SecurityWiring}, which derives the
     * default encryption subkey from the master key inside the same block that
     * binds it.
     */
    public function withFieldEncryption(): self
    {
        $bound = $this->bindings['Pulsar\Security\Crypto\MasterKey'] ?? null;
        $masterKey = $bound instanceof MasterKey ? $bound : MasterKey::fromHex(bin2hex(random_bytes(32)));

        return $this->resolvingInstance(EncryptorInterface::class, Encryptor::fromMasterKey($masterKey));
    }

    /**
     * A deployment whose at-rest protection is opaque and nothing more.
     *
     * The shape a custom binding actually takes. `EncryptorInterface` is `#[Api]`
     * and the composition root binds it BY CONTRACT, so an application can answer
     * it with a legacy cipher, an HSM shim or a wrapper someone wrote to carry a
     * key id — and none of those is necessarily authenticated or randomised. This
     * deployment conceals the value and gives it back, which is what most people
     * check, and lets whoever can write the row choose what the application reads
     * back about a data subject.
     *
     * It is the counter-shape to {@see withFieldEncryption()}: without it, a
     * control resting on the personal-data seal would be an instrument stuck at
     * Satisfied whenever any encryptor is bound at all.
     */
    public function withUnauthenticatedFieldEncryption(): self
    {
        return $this->resolvingInstance(EncryptorInterface::class, new UnauthenticatedFieldEncryptor());
    }

    /**
     * A pseudonymisation subsystem that actually replaces, resolves and erases.
     *
     * Built for real rather than {@see hollow()}, for the reason the token vault
     * and the session cipher are: {@see \Pulsar\Compliance\Evidence\PseudonymizationObserver}
     * mints a pseudonym, reads the mapping back, resolves it and then erases it
     * through the live services, so an object of the right class establishes
     * nothing. A `PseudonymizationService` conjured without its constructor holds
     * no derived key and no lookup, and would throw on the first call — correctly,
     * because a service with no key replaces nothing.
     *
     * All three bindings come from ONE lookup, which is what SecurityWiring
     * produces: the service that stores a mapping and the erasure service that
     * deletes it have to reach the same table, or the check would erase from a
     * second copy nobody uses and report success.
     *
     * The audit logger is real too, and it is not incidental — `resolve()` and
     * `forget()` both record what they did, and a stub that swallowed those calls
     * would let a deployment pass this check with no evidence of the erasure it
     * performed.
     */
    public function withWorkingPseudonymization(): self
    {
        return $this->withPseudonymization(new FilePseudonymLookup(
            $this->stateDirectory() . DIRECTORY_SEPARATOR . 'pseudonyms.json',
        ));
    }

    /**
     * The mapping table this framework shipped for years: the development stub.
     *
     * Every subject of the measurement passes over it — a pseudonym is minted,
     * recorded, resolved and erased, all inside one process — and the mappings are
     * gone at the end of the request. The pseudonymised records it produced can
     * never be resolved for an Article 15 answer and an Article 17 request has
     * nothing to erase. This is the deployment that makes an in-process
     * measurement insufficient on its own, and a test that cannot express it
     * cannot guard against it.
     */
    public function withNonDurablePseudonymTable(): self
    {
        return $this->withPseudonymization(new InMemoryPseudonymLookup());
    }

    /**
     * The durable table, bound, holding a document nothing can read.
     *
     * Every identity fact reads clean — `PseudonymLookupInterface` resolves to
     * `FilePseudonymLookup`, the implementation this release accepts — and the
     * first `pseudonymize()` throws, because that class refuses to treat an
     * unreadable table as an empty one: answering "no mapping" from a corrupt
     * document would report an erasure as already done and a live pseudonym as
     * unknown. So no identifier is ever replaced, and no resolution can tell.
     *
     * The pseudonymisation analogue of {@see withUnusableTokenVault()}, and the
     * shape that shows the measurement failing where the resolutions cannot. A
     * corrupt document rather than an unwritable path, because every way of making
     * a path unwritable raises a PHP warning from inside the code under test, and
     * this suite fails on warnings.
     */
    public function withCorruptPseudonymTable(): self
    {
        $path = $this->stateDirectory() . DIRECTORY_SEPARATOR . 'corrupt_pseudonyms.json';
        file_put_contents($path, '{ this is not a mapping table');

        return $this->withPseudonymization(new FilePseudonymLookup($path));
    }

    /**
     * An incident register that actually retains what it is given.
     *
     * Real, over a real file, for the reason the token vault is real:
     * {@see \Pulsar\Compliance\Evidence\IncidentRegisterObserver} records an
     * incident and reads it back by id, and on the file register that read goes to
     * disk — which is the only thing that distinguishes a register that survives
     * the process from one that answers out of an array.
     */
    public function withWorkingIncidentRegister(): self
    {
        return $this->resolvingInstance(
            IncidentReporterInterface::class,
            new FileIncidentReporter($this->stateDirectory() . DIRECTORY_SEPARATOR . 'incidents.jsonl'),
        );
    }

    /**
     * The register that empties on restart.
     *
     * It passes every subject of the measurement — recorded, found, intact — and
     * has forgotten the incident by the next request, so no notification deadline
     * it holds can be evidenced. The reason
     * {@see \Pulsar\Compliance\Control\ObservationId::IncidentReporterResolved}
     * stays an essential fact beside the measurement.
     */
    public function withNonDurableIncidentRegister(): self
    {
        return $this->resolvingInstance(IncidentReporterInterface::class, new InMemoryIncidentReporter());
    }

    /**
     * The three pseudonymisation bindings, over whichever table the caller chose.
     *
     * The key is the one the deployment already holds when there is one, so the
     * fixture matches SecurityWiring: the pseudonymisation subkey is derived from
     * the master key rather than from a key of its own. When there is none, a
     * throwaway is derived and NOT bound, for the reason
     * {@see withWorkingSessionSeal()} gives.
     */
    private function withPseudonymization(PseudonymLookupInterface $lookup): self
    {
        $bound = $this->bindings['Pulsar\Security\Crypto\MasterKey'] ?? null;
        $masterKey = $bound instanceof MasterKey ? $bound : MasterKey::fromHex(bin2hex(random_bytes(32)));

        $auditLogger = new AuditLogger(
            new AuditFileSink($this->stateDirectory() . DIRECTORY_SEPARATOR . 'audit.log'),
            bin2hex(random_bytes(32)),
        );

        return $this
            ->resolvingInstance(PseudonymLookupInterface::class, $lookup)
            ->resolvingInstance(PseudonymizationServiceInterface::class, new PseudonymizationService(
                $masterKey,
                $lookup,
                Encryptor::fromMasterKey($masterKey),
                $auditLogger,
            ))
            ->resolvingInstance(ForgetServiceInterface::class, new ForgetService($lookup, $auditLogger));
    }

    /**
     * A writable directory this deployment's stateful subsystems share.
     *
     * One per fixture, created lazily, and shared on purpose: SecurityWiring puts
     * the pseudonym table, the incident register and the audit trail beside each
     * other under the audit log's directory, and a fixture that scattered them
     * would not be building the deployment it claims to.
     */
    private function stateDirectory(): string
    {
        if ($this->stateDirectory !== null) {
            return $this->stateDirectory;
        }

        $path = sys_get_temp_dir() . '/pulsar_compliance_state_' . bin2hex(random_bytes(6));
        @mkdir($path, 0o700, true);

        return $this->stateDirectory = $path;
    }

    /**
     * A session cipher that is bound, constructible, and seals nothing.
     *
     * The deployment `session_encryption_resolved` cannot tell from a working
     * one: the class is right, the container built it, and every identity fact
     * reads clean. What it does is return the payload base64-encoded, which is
     * opaque enough to pass a naive "the output is not the input" check and is
     * cleartext to anyone who can read the session store. It is here so the
     * measurement can be shown to FAIL on a deployment the configuration read
     * cannot fault — the session-payload analogue of
     * {@see withUnusableTokenVault()}.
     */
    public function withCiphertextThatIsNotSealed(): self
    {
        return $this->resolvingInstance(
            'Pulsar\Security\Session\SessionEncryption',
            new class implements SessionPayloadCipherInterface {
                #[Override]
                public function encrypt(string $data, string $sessionId, string $handlerType, string $domain): string
                {
                    return base64_encode($data);
                }

                #[Override]
                public function decrypt(
                    string $encrypted,
                    string $sessionId,
                    string $handlerType,
                    string $domain,
                ): string {
                    return (string) base64_decode($encrypted, true);
                }
            },
        );
    }

    private function withTokenVault(bool $tableExists): self
    {
        $connection = new PdoConnection(
            connectionName: 'compliance-fixture',
            driver: Driver::SQLite,
            dsn: 'sqlite::memory:',
            username: null,
            password: null,
        );

        if ($tableExists) {
            $connection->execute(
                'CREATE TABLE token_vault ('
                    . 'token TEXT PRIMARY KEY, '
                    . 'encrypted_value TEXT NOT NULL, '
                    . 'context TEXT NOT NULL, '
                    . 'created_at TEXT NOT NULL)',
            );
        }

        $store = new DatabaseTokenStore($connection);
        $masterKey = MasterKey::fromHex(bin2hex(random_bytes(32)));

        return $this
            ->resolvingInstance('Pulsar\Security\Crypto\TokenStoreInterface', $store)
            ->resolvingInstance('Pulsar\Security\Crypto\TokenizationServiceInterface', new TokenizationService(
                $masterKey,
                $store,
            ))
            ->resolvingInstance('Pulsar\Security\Crypto\MasterKey', $masterKey)
            // SecurityWiring binds both in the same block, and the compliance fact
            // reads the interface rather than the concrete class — see
            // ControlEvidenceGatherer::IDENTITY_FACTS.
            ->resolvingInstance('Pulsar\Security\Crypto\KeyProviderInterface', $masterKey);
    }

    /**
     * A route that handles regulated data and carries every control its
     * classification requires.
     */
    public function withClassifiedRoute(): self
    {
        return $this->withRoute(new Route(
            methods: [Method::POST],
            path: '/payments',
            handler: static fn(): null => null,
            attributes: [RouteInventory::CLASSIFICATION_ATTRIBUTE => 'pci'],
            middleware: ['encryption', 'authentication', 'audit', 'csrf'],
        ));
    }

    /**
     * Bind a contract to an instance of the named concrete class.
     *
     * @param class-string $concrete
     */
    public function resolving(string $contract, string $concrete): self
    {
        return $this->resolvingInstance($contract, self::hollow($concrete));
    }

    /**
     * An instance of $class that exists without having been constructed.
     *
     * The gatherer records `$instance::class` and compares it against an accept
     * list, so what a test needs is an object of the right class and nothing more;
     * running the real constructors would mean standing up a database connection, a
     * key ring and a TOTP store to assert a fact about class identity.
     *
     * Uninitialized non-readonly typed properties are given a zero value of their
     * own type afterwards. Without that, a destructor that touches its own state —
     * {@see \Pulsar\Security\Session\SessionEncryption} zeroes its key in
     * `__destruct()` — fatals at script shutdown, after the assertions have already
     * passed. Readonly properties are left alone: they cannot be initialized from
     * outside their declaring scope, and no destructor in the accept lists reads one.
     *
     * @param class-string $class
     */
    private static function hollow(string $class): object
    {
        $reflection = new ReflectionClass($class);
        $instance = $reflection->newInstanceWithoutConstructor();

        foreach ($reflection->getProperties() as $property) {
            if ($property->isStatic() || $property->isReadOnly() || $property->isInitialized($instance)) {
                continue;
            }

            $type = $property->getType();

            if (!$type instanceof ReflectionNamedType) {
                continue;
            }

            $zero = match (true) {
                $type->allowsNull() => null,
                $type->getName() === 'string' => '',
                $type->getName() === 'int' => 0,
                $type->getName() === 'float' => 0.0,
                $type->getName() === 'bool' => false,
                $type->getName() === 'array' => [],
                default => null,
            };

            if ($zero === null && !$type->allowsNull()) {
                continue;
            }

            $property->setValue($instance, $zero);
        }

        return $instance;
    }

    /**
     * Bind a contract to an instance the test built itself.
     */
    public function resolvingInstance(string $contract, object $instance): self
    {
        $this->bindings[$contract] = $instance;
        $this->gathered = null;

        return $this;
    }

    /**
     * Register an extension id, as the extension registry would report it.
     */
    public function withExtension(string $id): self
    {
        $this->extensions[] = $id;
        $this->gathered = null;

        return $this;
    }

    /**
     * Record an operator scope assertion under the given config key.
     */
    public function assertingScope(string $key, bool $inScope): self
    {
        $this->scope[$key] = $inScope;
        $this->gathered = null;

        return $this;
    }

    public function withHealthCheck(HealthCheckInterface $check): self
    {
        $this->healthChecks[] = $check;
        $this->gathered = null;

        return $this;
    }

    /**
     * Give the deployment a real evidence chain holding one real, signed record,
     * so chain verification has something to recompute.
     */
    public function withVerifiedEvidenceChain(): self
    {
        $this->withChain = true;
        $this->gathered = null;

        return $this;
    }

    /**
     * A deployment where no compliance profile was resolved into the container —
     * what a boot with no framework enabled leaves behind.
     */
    public function withoutComplianceProfile(): self
    {
        $this->withProfile = false;
        $this->gathered = null;

        return $this;
    }

    public function withRoute(Route $route): self
    {
        $this->routes[] = $route;
        $this->gathered = null;

        return $this;
    }

    /**
     * A deployment whose database is reached over a network, and whose server was
     * asked what the live session negotiated.
     *
     * The only shape in which `database_transport_encrypted` is MEASURED. It needs
     * three things together and none of them alone: a `config/database.php` naming
     * a connection whose engine crosses a network, a host that is not a unix socket
     * — {@see \Pulsar\Compliance\Evidence\DatabaseTlsObserver::isLocalIpcConnection()}
     * excludes those, which is the whole reason HIPAA 164.312(e)(1) used to
     * evaporate — and a live connection that answers the `pg_stat_ssl` catalogue
     * read.
     *
     * The connection is a double rather than a real server: what is under test is
     * the decision table, and standing up PostgreSQL to prove that an encrypted
     * session satisfies a transmission-security control would make the test
     * unrunnable in the one place it has to run.
     */
    public function withNetworkedDatabase(bool $sessionEncrypted): self
    {
        $this->networkedDatabase = true;
        $this->gathered = null;

        return $this->resolvingInstance(
            ConnectionInterface::class,
            self::postgresReporting($sessionEncrypted),
        );
    }

    /**
     * A deployment whose security preflight found HSTS asserted with a sufficient
     * max-age.
     *
     * Bound as the posture REPORT the preflight produced, because that is what the
     * composition root reads. Without one, every posture fact records that the
     * preflight produced no such item — which is what the bare fixture is, and why
     * `transport_security_enforced` reads as a gap there.
     */
    public function withHstsEnforced(): self
    {
        return $this->resolvingInstance(
            SecurityPostureReport::class,
            new SecurityPostureReport([
                SecurityPostureItem::ok('https_hsts', 'HSTS is enabled with a sufficient max-age'),
            ]),
        );
    }

    /**
     * A connection that answers the catalogue read `TransportSecurity` puts to
     * PostgreSQL, and nothing else.
     *
     * Every other method throws rather than returning a benign default: a double
     * that quietly answers a question the code under test was not supposed to ask
     * is how a test stops testing what it claims to.
     */
    private static function postgresReporting(bool $encrypted): ConnectionInterface
    {
        return new class ($encrypted) implements ConnectionInterface {
            public function __construct(private readonly bool $encrypted) {}

            #[Override]
            public function query(string $sql, array $bindings = []): Result
            {
                if (!str_contains($sql, 'pg_stat_ssl')) {
                    throw new RuntimeException('Unexpected query in the transport fixture: ' . $sql);
                }

                return Result::fromArrays([[
                    'ssl' => $this->encrypted ? 't' : 'f',
                    'version' => 'TLSv1.3',
                    'cipher' => 'TLS_AES_256_GCM_SHA384',
                ]]);
            }

            #[Override]
            public function driver(): Driver
            {
                return Driver::PostgreSQL;
            }

            #[Override]
            public function name(): string
            {
                return 'primary';
            }

            #[Override]
            public function execute(string $sql, array $bindings = []): int
            {
                throw new RuntimeException('The transport fixture does not execute statements.');
            }

            #[Override]
            public function prepare(string $sql): Statement
            {
                throw new RuntimeException('The transport fixture does not prepare statements.');
            }

            #[Override]
            public function beginTransaction(): Transaction
            {
                throw new RuntimeException('The transport fixture does not open transactions.');
            }

            #[Override]
            public function transaction(callable $callback): mixed
            {
                throw new RuntimeException('The transport fixture does not open transactions.');
            }

            #[Override]
            public function lastInsertId(): string
            {
                throw new RuntimeException('The transport fixture writes nothing.');
            }

            #[Override]
            public function variant(): DriverVariant
            {
                throw new RuntimeException('The transport fixture reports no server variant.');
            }

            #[Override]
            public function dialect(): DialectInterface
            {
                throw new RuntimeException('The transport fixture writes no SQL.');
            }

            #[Override]
            public function inTransaction(): bool
            {
                return false;
            }

            #[Override]
            public function disconnect(): void {}
        };
    }

    /**
     * Gather the evidence this deployment yields, through the real composition
     * root and the real gatherer.
     */
    public function evidence(): ControlEvidence
    {
        if ($this->gathered !== null) {
            return $this->gathered;
        }

        $container = new Container();
        $router = new Router();

        foreach ($this->routes as $route) {
            $router->add($route);
        }

        foreach ($this->bindings as $contract => $instance) {
            $container->instance($contract, $instance);
        }

        if ($this->healthChecks !== []) {
            $runner = new HealthCheckRunner();

            foreach ($this->healthChecks as $check) {
                $runner->register($check);
            }

            $container->instance(HealthCheckRunnerInterface::class, $runner);
        }

        if ($this->withChain) {
            $store = new InMemoryEvidenceStore();
            $chain = new EvidenceChain($store, bin2hex(random_bytes(32)));
            $chain->record(new VerificationReport(frameworks: $this->frameworks, results: []));

            $container->instance(EvidenceStoreInterface::class, $store);
            $container->instance(EvidenceChain::class, $chain);
        }

        if ($this->extensions !== []) {
            $container->instance(ExtensionRegistry::class, $this->registry());
        }

        if ($this->withProfile) {
            $container->instance(
                ComplianceProfile::class,
                new ComplianceProfileResolver()->resolve($this->frameworks),
            );
        }

        $configManager = $this->configManager();

        new ComplianceCatalogWiring()->wire(
            $container,
            $configManager,
            new MiddlewarePipeline($container),
            new MiddlewareRegistry(),
            $router,
        );

        $this->assessment = $container->get(ControlAssessment::class);

        /** @var ControlEvidenceGatherer $gatherer */
        $gatherer = $container->get(ControlEvidenceGatherer::class);

        return $this->gathered = $gatherer->gather();
    }

    /**
     * The finding for one control, as the real assessment produces it.
     */
    public function finding(ComplianceFramework $framework, string $id): ControlFinding
    {
        $evidence = $this->evidence();
        $assessment = $this->assessment;

        if ($assessment === null) {
            throw new RuntimeException('The catalog wiring did not bind a ControlAssessment.');
        }

        foreach ($assessment->assessFrameworks([$framework], $evidence) as $finding) {
            if ($finding->declaration->id === $id) {
                return $finding;
            }
        }

        throw new RuntimeException(sprintf(
            'No control "%s" is declared for framework "%s".',
            $id,
            $framework->value,
        ));
    }

    /**
     * A health check that always reports healthy, for the deployments that are
     * supposed to have monitoring.
     */
    public static function passingHealthCheck(string $name): HealthCheckInterface
    {
        return new class ($name) implements HealthCheckInterface {
            public function __construct(private readonly string $name) {}

            #[Override]
            public function getName(): string
            {
                return $this->name;
            }

            #[Override]
            public function check(): HealthCheckResult
            {
                return new HealthCheckResult(
                    name: $this->name,
                    status: HealthStatus::Healthy,
                    message: 'OK',
                    responseTimeMs: 0.0,
                    checkedAt: new DateTimeImmutable(),
                );
            }
        };
    }

    private function configManager(): ConfigManager
    {
        $manager = new ConfigManager($this->configPath());
        ConfigLoaderRegistrar::register($manager, WiringList::default());
        $manager->load();

        return $manager;
    }

    /**
     * An extension registry reporting exactly the ids the test named.
     */
    private function registry(): ExtensionRegistry
    {
        $registry = new ExtensionRegistry();

        foreach ($this->extensions as $id) {
            $extension = new class ($id) implements ExtensionInterface {
                public function __construct(private readonly string $id) {}

                #[Override]
                public function name(): string
                {
                    return $this->id;
                }

                #[Override]
                public function register(ContainerInterface $container): void {}

                #[Override]
                public function boot(ContainerInterface $container, RouterInterface $router): void {}

                #[Override]
                public function providers(): array
                {
                    return [];
                }
            };

            $registry->add(
                $extension,
                new ExtensionManifest($id, '1.0.0', $extension::class, sys_get_temp_dir()),
            );
        }

        return $registry;
    }

    private function configPath(): string
    {
        $path = sys_get_temp_dir() . '/pulsar_compliance_' . bin2hex(random_bytes(6));
        @mkdir($path, 0o755, true);

        file_put_contents(
            $path . '/app.php',
            '<?php return ["name" => "Test", "env" => "testing", "debug" => false, '
                . '"timezone" => "UTC", "locale" => "en"];',
        );
        file_put_contents(
            $path . '/observability.php',
            '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];',
        );
        file_put_contents(
            $path . '/security.php',
            '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];',
        );

        // Written only when the test asked for it. A deployment with no
        // config/database.php has no database transport at all, which is a
        // different fact from one whose transport is unencrypted, and the bare
        // fixture has to keep expressing the first.
        if ($this->networkedDatabase) {
            file_put_contents(
                $path . '/database.php',
                '<?php return ["default" => "primary", "connections" => ["primary" => ['
                    . '"driver" => "pgsql", "host" => "db.internal", "port" => 5432, '
                    . '"database" => "app", "username" => "app", "password" => "", '
                    . '"options" => ["sslmode" => "require"]]]];',
            );
        }

        $frameworks = implode(', ', array_map(
            static fn(ComplianceFramework $framework): string => var_export($framework->value, true),
            $this->frameworks,
        ));

        $scope = '';

        foreach ($this->scope as $key => $inScope) {
            $scope .= sprintf('%s => %s, ', var_export($key, true), $inScope ? 'true' : 'false');
        }

        file_put_contents(
            $path . '/compliance.php',
            sprintf(
                '<?php return ["enabled_frameworks" => [%s], "scope" => [%s], '
                    . '"verification" => ["enabled" => true, "boot_check" => false]];',
                $frameworks,
                $scope,
            ),
        );

        return $path;
    }
}
