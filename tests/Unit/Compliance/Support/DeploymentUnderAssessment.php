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
use Pulsar\Database\Driver;
use Pulsar\Database\PdoConnection;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\ExtensionManifest;
use Pulsar\Extensibility\ExtensionRegistry;
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
use Pulsar\Security\Crypto\DatabaseTokenStore;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Security\Crypto\TokenizationService;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;
use stdClass;

use function array_map;
use function bin2hex;
use function file_put_contents;
use function implode;
use function mkdir;
use function random_bytes;
use function sprintf;
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

    private bool $withProfile = true;

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
            ->resolving('Pulsar\Security\Session\SessionEncryption', 'Pulsar\Security\Session\SessionEncryption')
            ->resolving('Pulsar\Auth\TwoFactor\TwoFactorManagerInterface', 'Pulsar\Auth\TwoFactor\TwoFactorManager')
            ->resolving('Pulsar\Security\Incident\IncidentReporterInterface', 'Pulsar\Security\Incident\FileIncidentReporter')
            ->resolving('Pulsar\DataProtection\DataPurgeInterface', 'Pulsar\DataProtection\DataPurgeOrchestrator')
            ->resolving('Pulsar\DataProtection\RetentionPolicyInterface', 'Pulsar\DataProtection\DefaultRetentionPolicy')
            ->resolving(
                'Pulsar\Security\Compliance\Pseudonymization\PseudonymizationServiceInterface',
                'Pulsar\Security\Compliance\Pseudonymization\PseudonymizationService',
            )
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
            // The two optional bindings whose absence makes a security feature
            // inert; without them the wiring-contract detector vetoes every
            // control that requires nothing to be silently disabled.
            ->resolvingInstance('Pulsar\Cache\Application\TaggedCacheInterface', new stdClass())
            ->withVerifiedEvidenceChain()
            ->withHealthCheck(self::passingHealthCheck('database'))
            ->withClassifiedRoute();
    }

    /**
     * A token vault that actually works, and a key hierarchy that actually derives.
     *
     * These three bindings are the only ones in the fixture built for real rather
     * than {@see hollow()}, and the reason is the whole of ADR-0041's second
     * lesson. Every other fact in the evidence set is about which class answered a
     * contract, so an object of the right class is all a test needs. PAN-at-rest
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
