<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Compliance\ComplianceConfig;
use Pulsar\Compliance\ComplianceProfile;
use Pulsar\Compliance\Control\ControlAssessment;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Compliance\Evidence\AiGovernanceDrillInterface;
use Pulsar\Compliance\Evidence\AiGovernanceRecordObserver;
use Pulsar\Compliance\Evidence\AiMonitoringObserver;
use Pulsar\Compliance\Evidence\AiTransparencyDrillInterface;
use Pulsar\Compliance\Evidence\AiTransparencyObserver;
use Pulsar\Compliance\Evidence\BackupRoundTripObserver;
use Pulsar\Compliance\Evidence\ComplianceScope;
use Pulsar\Compliance\Evidence\ControlEvidenceGatherer;
use Pulsar\Compliance\Evidence\DatabaseTlsObserver;
use Pulsar\Compliance\Evidence\EvidenceSourceInterface;
use Pulsar\Compliance\Evidence\IncidentRegisterObserver;
use Pulsar\Compliance\Evidence\PersonalDataSealObserver;
use Pulsar\Compliance\Evidence\PseudonymizationObserver;
use Pulsar\Compliance\Evidence\ResolvedBindings;
use Pulsar\Compliance\Evidence\RouteInventory;
use Pulsar\Compliance\Evidence\SessionSealObserver;
use Pulsar\Compliance\Evidence\TokenVaultObserver;
use Pulsar\Compliance\Frameworks\AiActMapping;
use Pulsar\Compliance\Frameworks\CcpaMapping;
use Pulsar\Compliance\Frameworks\DoraMapping;
use Pulsar\Compliance\Frameworks\EidasMapping;
use Pulsar\Compliance\Frameworks\GdprMapping;
use Pulsar\Compliance\Frameworks\HipaaMapping;
use Pulsar\Compliance\Frameworks\Hl7FhirMapping;
use Pulsar\Compliance\Frameworks\Iso13485Mapping;
use Pulsar\Compliance\Frameworks\Iso27001Mapping;
use Pulsar\Compliance\Frameworks\Iso42001Mapping;
use Pulsar\Compliance\Frameworks\MdrMapping;
use Pulsar\Compliance\Frameworks\Nis2Mapping;
use Pulsar\Compliance\Frameworks\NistCsfMapping;
use Pulsar\Compliance\Frameworks\PciDssMapping;
use Pulsar\Compliance\Frameworks\Psd2Mapping;
use Pulsar\Compliance\Frameworks\Soc2Mapping;
use Pulsar\Compliance\Frameworks\SwiftCspMapping;
use Pulsar\Compliance\Verification\DataPathVerifier;
use Pulsar\Compliance\Verification\EvidenceChain;
use Pulsar\Compliance\Verification\RuntimeVerifier;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\DatabaseConfig;
use Pulsar\Container\ContainerInterface;
use Pulsar\Core\Wiring\Contract\DescribesWiring;
use Pulsar\Core\Wiring\Contract\WiringContractInspector;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extensibility\ExtensionRegistry;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Resilience\Backup\BackupDestination;
use Pulsar\Resilience\Backup\BackupServiceInterface;
use Pulsar\Resilience\HealthCheck\HealthCheckRunnerInterface;
use Pulsar\Routing\Router;
use Pulsar\Security\Compliance\Pseudonymization\ForgetServiceInterface;
use Pulsar\Security\Compliance\Pseudonymization\PseudonymizationServiceInterface;
use Pulsar\Security\Crypto\CipherSuiteInterface;
use Pulsar\Security\Crypto\EncryptorInterface;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Security\Crypto\TokenizationServiceInterface;
use Pulsar\Security\Crypto\TokenStoreInterface;
use Pulsar\Security\Incident\IncidentReporterInterface;
use Pulsar\Security\Posture\SecurityPostureReport;
use Pulsar\Security\Session\SessionEncryption;
use Random\Engine\Secure;
use Random\Randomizer;
use Throwable;

use function is_object;

/**
 * Binds the control catalog and the machinery that turns it into a compliance
 * report, and builds none of it.
 *
 * Three bindings, and NONE of them builds anything during boot:
 *
 *  - {@see ControlCatalog} is bound as a lazy singleton holding one deferred
 *    source, {@see declarations()}. The seventeen mapping classes are not
 *    autoloaded and not one of the 215 declarations is constructed until
 *    something reads the catalog, which only `compliance:report` and the
 *    `compliance:check` gate ever do.
 *  - {@see ControlAssessment} is lazy for the same reason and is equally inert
 *    once built: it is a function from (catalog, evidence) to findings, and it
 *    holds no evidence.
 *  - {@see ControlEvidenceGatherer} is bound as a LAZY closure and MUST stay that
 *    way. Everything that could be mistaken for a compliance claim happens inside
 *    it, and it must not happen at boot.
 *
 * WHY THE CATALOG IS LAZY. Not for the reason the gatherer is — a declaration
 * carries no outcome, so building one at boot asserts nothing about the
 * deployment and would be safe. It is lazy because it was not free.
 *
 * Measured by driving the real {@see WiringList::default()} loop against a real
 * ConfigManager over `config/` and timing each of the 51 wirings, seven runs of
 * 21 iterations, PHP 8.5.9 ZTS with opcache on, JIT off and Xdebug off. Warm is
 * the median of the in-process iterations, cold the first iteration of a fresh
 * process:
 *
 *                     warm      cold    share of warm loop   rank
 *   built at boot   0.520 ms  21.74 ms        2.28%          8/51
 *   built on read   0.025 ms   0.040 ms       0.11%         17/51
 *
 * Every request of every application paid the first row, including applications
 * that enable no compliance framework at all, for a structure no request reads.
 * What is left is four container bindings and no work.
 *
 * The cost did not vanish, it moved: the first read builds every declaration in
 * 0.36 ms warm and 75 ms cold (the mapping classes are autoloaded there), once
 * per `compliance:report` or `compliance:check` run. Both timings were taken over
 * the 193 declarations of sixteen mappings; the catalog holds 215 from seventeen
 * since the EU AI Act mapping landed, so they are a floor. They are left as
 * measured rather than scaled by arithmetic nobody ran, which is the same rule
 * this subsystem applies to a compliance status.
 *
 * Nothing stays eager. There is no boot-time consumer to keep eager FOR: the
 * request path never touches the catalog, and the boot-time compliance behaviour
 * an operator does enable in config — `verification.boot_check` — belongs to
 * {@see ComplianceVerificationWiring}, which verifies the profile against the
 * wired container and reads no declaration. What eager construction used to buy
 * was one thing, discovering a duplicate control identifier at boot, and that is
 * a defect in the mapping files rather than a property of a deployment: it is
 * caught by `composer compliance:check` and by the catalog's own tests, which is
 * where a build-integrity check belongs.
 *
 * WHY THE GATHERER IS LAZY, in detail, because this is the single most dangerous
 * implementation detail in the design. Gathering resolves TokenStoreInterface to
 * find out which token vault serves this deployment. {@see SecurityWiring} is
 * eighth in {@see WiringList} and {@see DatabaseWiring} seventeenth, so resolving
 * it during wiring resolves it BEFORE the database exists — and the container
 * caches singletons, so the answer would be InMemoryTokenStore for the rest of
 * the process. The report would then measure, print, and HMAC-chain into the
 * evidence record a vault the running application does not use. That is exactly
 * the ordering bug ADR-0041 fixed, re-introduced by the thing built to detect it.
 *
 * Gathering also has cost and side effects: it opens a database session and
 * queries it, it EXECUTES every registered health check, it recomputes one HMAC
 * per stored evidence record, it tokenizes one synthetic value through the live
 * token vault and removes it again ({@see TokenVaultObserver}), and it declares
 * one reserved Article 50 surface and mints a synthetic-content mark through the
 * live transparency subsystem ({@see AiTransparencyObserver}). It also
 * pseudonymises a synthetic identifier and erases it again
 * ({@see PseudonymizationObserver}) and records one Low-severity incident it
 * cannot take back ({@see IncidentRegisterObserver}). It registers one reserved AI
 * model, opens an impact assessment against it, writes one data quality report and
 * one explanation, and reads each back through a SECOND store instance
 * ({@see AiGovernanceRecordObserver}); and it runs every registered monitoring hook
 * against that model, reads the retained results back the same way, and then
 * disposes of exactly the records it wrote ({@see AiMonitoringObserver}). Those six
 * are the writes in the evidence set, and each is the only thing that tells a
 * subsystem that works from one that merely resolves. None of that belongs in a
 * request boot.
 *
 * TWO OBSERVERS COST WITHOUT WRITING, and they are named here so the list above
 * is not read as the whole of what runs: {@see SessionSealObserver} seals and
 * opens a synthetic session payload four times over, and
 * {@see PersonalDataSealObserver} seals a synthetic personal-data field twice and
 * opens it twice. Both work on at-rest forms the subsystem RETURNS, so nothing is
 * persisted and there is no cleanup that can fail.
 *
 * @see \Pulsar\Compliance\Evidence\ControlEvidenceGatherer
 */
#[Internal]
final readonly class ComplianceCatalogWiring implements ServiceWiringInterface
{
    /**
     * The contracts whose resolved identity the evidence gatherer reasons about.
     *
     * Resolving them is done HERE, in the composition root, and only the resulting
     * class names travel onward. A gatherer holding the container would be a
     * service locator, and — worse — could ask the container the one question
     * ADR-0041 proved worthless: whether a class can be constructed.
     *
     * Names are strings rather than `::class` on purpose: several belong to
     * extensions the Compliance module may not import, and one of them
     * deliberately names a contract nothing in the framework provides.
     *
     * @var list<string>
     */
    private const array OBSERVED_CONTRACTS = [
        'Pulsar\Security\Crypto\TokenStoreInterface',
        'Pulsar\Security\Audit\AuditSinkInterface',
        'Pulsar\Compliance\Evidence\EvidenceStoreInterface',
        'Pulsar\Security\Session\SessionEncryption',
        'Pulsar\Security\Crypto\MasterKey',
        // The contract the master-key fact is judged against. `MasterKey` itself
        // stays on the list because RuntimeVerifier is handed the instance to run
        // the KDF against, but the compliance fact reads the INTERFACE: naming a
        // concrete final class as both the contract and its only implementation
        // makes the resolution "is an X bound", which is the question ADR-0041
        // proved worthless. SecurityWiring binds both in the same block, and which
        // of MasterKey or CompositeKeyProvider answers is a real fact about the
        // deployment's key hierarchy.
        'Pulsar\Security\Crypto\KeyProviderInterface',
        'Pulsar\Auth\TwoFactor\TwoFactorManagerInterface',
        'Pulsar\Auth\TwoFactor\TwoFactorRateLimiterInterface',
        'Pulsar\Security\Incident\IncidentReporterInterface',
        'Pulsar\DataProtection\ConsentManagerInterface',
        'Pulsar\DataProtection\DataPurgeInterface',
        'Pulsar\DataProtection\Dsar\DsarStoreInterface',
        'Pulsar\DataProtection\RetentionPolicyInterface',
        'Pulsar\Security\Compliance\Pseudonymization\PseudonymizationServiceInterface',
        // The mapping table under that service, observed as its own fact. The
        // service and the table fail differently and neither substitutes for the
        // other: the service is what replaces an identifier, the table is what
        // makes the replacement survive the request that made it.
        'Pulsar\Security\Compliance\Pseudonymization\PseudonymLookupInterface',
        'Pulsar\Observability\Tracing\SpanProcessorInterface',
        ControlEvidenceGatherer::BACKUP_SERVICE_CONTRACT,
        'Pulsar\Extension\AiGovernance\Contracts\AiModelRegistryInterface',
        'Pulsar\Extension\AiGovernance\Contracts\AiImpactAssessmentInterface',
        'Pulsar\Extension\AiGovernance\Contracts\AiAuditLoggerInterface',
        'Pulsar\Extension\AiGovernance\Contracts\AiDataGovernanceInterface',
        'Pulsar\Extension\AiGovernance\Contracts\ExplainabilityInterface',
        'Pulsar\Extension\AiGovernance\Contracts\AiLifecycleManagerInterface',
        'Pulsar\Extension\AiGovernance\Contracts\DeploymentGateInterface',
        'Pulsar\Extension\AiGovernance\Contracts\MonitoringHookInterface',
        // Absent from this list until now, which made `ai_transparency_resolved`
        // structurally unreachable: nothing resolved the contract, so the fact read
        // "nothing answered AiTransparencyInterface" against containers that had
        // bound it, and ai-act-art-50-capability could reach neither outcome for a
        // reason that was an omission here rather than a property of any deployment.
        'Pulsar\Extension\AiGovernance\Contracts\AiTransparencyInterface',
    ];

    #[Override]
    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        // A singleton closure rather than an instance: binding an instance would
        // autoload ControlCatalog itself during boot, and `has()` — which is all
        // the console asks before registering the report command — is answered
        // from the binding without resolving it.
        $container->singleton(
            ControlCatalog::class,
            static function (): ControlCatalog {
                $catalog = new ControlCatalog();

                // The first-class callable is created here; the method body, and
                // with it every mapping class, runs at the catalog's first read.
                $catalog->contribute(self::declarations(...));

                return $catalog;
            },
        );

        $container->singleton(
            ControlAssessment::class,
            static function () use ($container): ControlAssessment {
                /** @var ControlCatalog $catalog */
                $catalog = $container->get(ControlCatalog::class);

                return new ControlAssessment($catalog);
            },
        );

        $container->singleton(
            ControlEvidenceGatherer::class,
            static fn(): ControlEvidenceGatherer => self::gatherer($container, $configManager, $router),
        );

        // The report command depends on the interface, not on this class: the
        // gatherer is #[Internal] and the console must not import it. Aliasing
        // rather than re-registering keeps ONE lazy singleton, so a run that
        // resolves both names still observes the deployment exactly once.
        $container->singleton(
            EvidenceSourceInterface::class,
            static fn(): EvidenceSourceInterface => $container->get(ControlEvidenceGatherer::class),
        );
    }

    /**
     * Every control the framework declares, from the mappings that declare them.
     *
     * The catalog's deferred source. Nothing calls this during boot: it runs at
     * the catalog's first read, and it is the single most expensive thing this
     * wiring can do — the seventeen mapping classes below are autoloaded here and
     * nowhere else, and each returns its declarations with their probes already
     * constructed.
     *
     * @return list<\Pulsar\Compliance\Control\ControlDeclaration>
     */
    private static function declarations(): array
    {
        return [
            ...AiActMapping::declarations(),
            ...CcpaMapping::declarations(),
            ...DoraMapping::declarations(),
            ...EidasMapping::declarations(),
            ...GdprMapping::declarations(),
            ...HipaaMapping::declarations(),
            ...Hl7FhirMapping::declarations(),
            ...Iso13485Mapping::declarations(),
            ...Iso27001Mapping::declarations(),
            ...Iso42001Mapping::declarations(),
            ...MdrMapping::declarations(),
            ...Nis2Mapping::declarations(),
            ...NistCsfMapping::declarations(),
            ...PciDssMapping::declarations(),
            ...Psd2Mapping::declarations(),
            ...Soc2Mapping::declarations(),
            ...SwiftCspMapping::declarations(),
        ];
    }

    /**
     * Build the gatherer from the fully booted container.
     *
     * Only ever called from the lazy binding above, which is to say: when a report
     * is asked for, never during boot.
     */
    private static function gatherer(
        ContainerInterface $container,
        ConfigManager $configManager,
        Router $router,
    ): ControlEvidenceGatherer {
        $repository = $configManager->repository();

        $profile = $container->has(ComplianceProfile::class)
            ? $container->get(ComplianceProfile::class)
            : null;

        $scope = $repository->has(ComplianceConfig::class)
            ? $repository->get(ComplianceConfig::class)->scope
            : new ComplianceScope();

        $databaseConfig = $repository->has(DatabaseConfig::class)
            ? $repository->get(DatabaseConfig::class)
            : null;

        $observer = new DatabaseTlsObserver();

        return new ControlEvidenceGatherer(
            posture: $container->has(SecurityPostureReport::class)
                ? $container->get(SecurityPostureReport::class)
                // An empty report is not a passing one: every posture fact then
                // records that the preflight produced no such item, which is what
                // actually happened.
                : new SecurityPostureReport([]),
            // The master key and the cipher suite are resolved, not merely counted:
            // `runtime.master_key_derived` runs the KDF against the key that is
            // actually in service, and `runtime.fips_mode` grades the suite the
            // deployment encrypts with. Neither question can be answered by has().
            runtimeChecks: $profile === null ? [] : new RuntimeVerifier(
                profile: $profile,
                sessionEncryptionActive: $container->has(SessionEncryption::class),
                masterKey: $container->has(MasterKey::class)
                    ? $container->get(MasterKey::class)
                    : null,
                auditLogActive: $container->has('Pulsar\Audit\AuditLoggerInterface'),
                dbTlsActive: $observer->configuredForTls($databaseConfig),
                activeCipherSuite: $container->has(CipherSuiteInterface::class)
                    ? $container->get(CipherSuiteInterface::class)
                    : null,
            )->verify(),
            degradedFeatures: self::degradedFeatures($container),
            inspectedWiringContracts: self::inspectedWiringContracts(),
            activeExtensions: self::activeExtensions($container),
            scope: $scope,
            bindings: self::resolveBindings($container),
            databaseTls: $observer,
            routes: new RouteInventory($router->routes()),
            tokenVault: new TokenVaultObserver(
                $container->has(Randomizer::class)
                    ? $container->get(Randomizer::class)
                    : new Randomizer(new Secure()),
            ),
            aiTransparency: new AiTransparencyObserver(),
            aiGovernanceRecords: new AiGovernanceRecordObserver(),
            aiMonitoring: new AiMonitoringObserver(),
            sessionSeal: new SessionSealObserver(
                $container->has(Randomizer::class)
                    ? $container->get(Randomizer::class)
                    : new Randomizer(new Secure()),
            ),
            pseudonymization: new PseudonymizationObserver(
                $container->has(Randomizer::class)
                    ? $container->get(Randomizer::class)
                    : new Randomizer(new Secure()),
            ),
            incidentRegister: new IncidentRegisterObserver(
                $container->has(Randomizer::class)
                    ? $container->get(Randomizer::class)
                    : new Randomizer(new Secure()),
            ),
            personalDataSeal: new PersonalDataSealObserver(
                $container->has(Randomizer::class)
                    ? $container->get(Randomizer::class)
                    : new Randomizer(new Secure()),
            ),
            backupRoundTrip: new BackupRoundTripObserver(
                $container->has(Randomizer::class)
                    ? $container->get(Randomizer::class)
                    : new Randomizer(new Secure()),
            ),
            dataPaths: $profile === null ? null : new DataPathVerifier($profile),
            profile: $profile,
            databaseConfig: $databaseConfig,
            connection: $container->has(ConnectionInterface::class)
                ? $container->get(ConnectionInterface::class)
                : null,
            // The store is NOT passed: the gatherer no longer reads the register
            // itself. Handing a pre-read record list to a verifier is how a
            // register the store was reporting as unreadable came to be certified
            // from whatever happened to decode, so the chain owns the read and the
            // gatherer grades the verdict.
            evidenceChain: $container->has(EvidenceChain::class)
                ? $container->get(EvidenceChain::class)
                : null,
            health: $container->has(HealthCheckRunnerInterface::class)
                ? $container->get(HealthCheckRunnerInterface::class)
                : null,
            // The vault is resolved here, in the composition root, and the two
            // halves are resolved SEPARATELY on purpose: reading the persisted
            // record back through the service that wrote it would only prove the
            // service can decrypt its own output, never that the bytes at rest
            // conceal anything.
            tokenizer: $container->has(TokenizationServiceInterface::class)
                ? $container->get(TokenizationServiceInterface::class)
                : null,
            tokenStore: $container->has(TokenStoreInterface::class)
                ? $container->get(TokenStoreInterface::class)
                : null,
            // The Article 50 seam, and null when no extension answered it. The
            // ai-governance package is trust tier `verified` and kind `product`, so
            // it does not load unless an operator enabled it; a deployment without
            // it must produce an ABSENT transparency fact rather than a false one,
            // and that is what handing null over does.
            transparencyDrill: $container->has(AiTransparencyDrillInterface::class)
                ? $container->get(AiTransparencyDrillInterface::class)
                : null,
            // The seam over the AI governance RECORD and its monitoring, and null
            // when no extension answered it. Same shape and same reason as the
            // transparency drill above: the package is optional, so a deployment
            // without it produces ABSENT facts rather than false ones. It reaches
            // the stores an application's own models are registered in, which is
            // what lets the report tell a store that retains from one that
            // remembers without knowing any class's name.
            governanceDrill: $container->has(AiGovernanceDrillInterface::class)
                ? $container->get(AiGovernanceDrillInterface::class)
                : null,
            // THE SAME OBJECT THE SESSION MANAGER HOLDS, and that is the whole
            // value of resolving it here rather than building one. SecurityWiring
            // constructs exactly one SessionEncryption, binds it under this id and
            // passes that instance to the SessionManager it constructs in the same
            // block, so what SessionSealObserver seals a payload with is the cipher
            // in the write path — not an equivalent one built for the report, which
            // would measure this deployment's libsodium and nothing else about it.
            // Resolved by the concrete id rather than by the published contract
            // because that is the id SecurityWiring binds; the contract exists so
            // Compliance can HOLD the thing, not to add a second binding.
            sessionCipher: $container->has(SessionEncryption::class)
                ? $container->get(SessionEncryption::class)
                : null,
            // THE SAME ENCRYPTOR EVERY CLASSIFIED FIELD IS SEALED WITH, and
            // resolved by the published contract rather than by the concrete class
            // for the reason the session cipher is resolved by its concrete id:
            // this is the id SecurityWiring binds an application may override, and
            // an application that binds its own EncryptorInterface is exactly the
            // deployment whose at-rest protection has to be measured rather than
            // assumed. Null when PULSAR_MASTER_KEY never loaded, which makes the
            // fact ABSENT — the whole crypto block is skipped without a key, so
            // there is nothing sealing anything.
            encryptor: $container->has(EncryptorInterface::class)
                ? $container->get(EncryptorInterface::class)
                : null,
            // Pseudonymisation, in both halves, and separately for the reason the
            // vault's two halves are separate: the step that ERASES is the control
            // Art 17 names, and taking it from the service that minted the mapping
            // would let a deployment evidence erasure with a service that only says
            // it erased. SecurityWiring binds both in the same block — a deployment
            // has both or neither — but "in practice they arrive together" is not a
            // thing the gatherer should have to assume, and a deployment that
            // overrides one is expressible.
            //
            // The service binding is a deferred singleton because its constructor
            // derives a subkey; resolving it here is what makes that derivation
            // happen, which is correct — the report is exactly the moment the
            // deployment's pseudonymisation is supposed to be exercised.
            pseudonymizer: $container->has(PseudonymizationServiceInterface::class)
                ? $container->get(PseudonymizationServiceInterface::class)
                : null,
            forgetService: $container->has(ForgetServiceInterface::class)
                ? $container->get(ForgetServiceInterface::class)
                : null,
            // The register that receives incidents from the threat-detection
            // engine, the audit anomaly detector, the access-pattern monitor and
            // the break-the-glass middleware. It is the one the application would
            // write a real breach into, which is the only register whose behaviour
            // says anything about a notification deadline.
            incidentReporter: $container->has(IncidentReporterInterface::class)
                ? $container->get(IncidentReporterInterface::class)
                : null,
            // THE SERVICE THAT WOULD TAKE THIS DEPLOYMENT'S BACKUPS, and the
            // directory its archives actually land in. Both are resolved rather
            // than constructed, and that is the whole value: a round trip against
            // a service the report built for itself, writing to a temporary
            // directory, would establish that libsodium works — the mistake
            // ADR-0061 removed — while an unwritable backup destination or a
            // deployment that never bound the primitive would still read clean.
            //
            // Null when PULSAR_MASTER_KEY never loaded. BackupWiring binds nothing
            // in that case rather than writing an unsealed archive, and the
            // round-trip fact is then ABSENT, which fails the three recovery
            // controls exactly as it should.
            backupService: $container->has(BackupServiceInterface::class)
                ? $container->get(BackupServiceInterface::class)
                : null,
            backupDestination: $container->has(BackupDestination::class)
                ? $container->get(BackupDestination::class)
                : null,
        );
    }

    /**
     * Ask the container which concrete class answers each observed contract, and
     * record the answer.
     *
     * A contract nothing answers is simply left out, and a contract whose
     * resolution throws is treated the same way: a binding that cannot be built is
     * not serving requests either, and letting the exception escape would turn a
     * misconfigured optional subsystem into a failed report.
     */
    private static function resolveBindings(ContainerInterface $container): ResolvedBindings
    {
        $resolved = [];

        foreach (self::OBSERVED_CONTRACTS as $contract) {
            if (!$container->has($contract)) {
                continue;
            }

            try {
                /** @var mixed $instance */
                $instance = $container->get($contract);
            } catch (Throwable) {
                continue;
            }

            if (is_object($instance)) {
                $resolved[$contract] = $instance::class;
            }
        }

        return new ResolvedBindings($resolved);
    }

    /**
     * Security features left inert by a missing optional binding, judged against
     * the fully wired container — the same detector {@see SecurityPostureWiring}
     * uses, and the only mechanism in the tree that tells "bound" from "working".
     *
     * @return list<\Pulsar\Core\Wiring\Contract\DegradedFeature>
     */
    private static function degradedFeatures(ContainerInterface $container): array
    {
        return new WiringContractInspector($container)->degradedFeatures(self::wiringContracts());
    }

    /**
     * The components whose wiring contracts were read, so the gatherer can tell an
     * inspection that found nothing wrong from one that never ran.
     *
     * An empty {@see degradedFeatures()} means one of two opposite things, and
     * only this list separates them. Without it, a gatherer constructed with no
     * degraded features published "no security feature is inert" at a grade that
     * carries a control, on the strength of nobody having looked.
     *
     * @return list<string>
     */
    private static function inspectedWiringContracts(): array
    {
        $components = [];

        foreach (self::wiringContracts() as $contract) {
            $components[] = $contract->component;
        }

        return $components;
    }

    /**
     * @return list<\Pulsar\Core\Wiring\Contract\WiringContract>
     */
    private static function wiringContracts(): array
    {
        $contracts = [];

        foreach (WiringList::default() as $wiring) {
            if ($wiring instanceof DescribesWiring) {
                $contracts[] = $wiring->describeWiring();
            }
        }

        return $contracts;
    }

    /**
     * The ids of the extensions this deployment actually registered.
     *
     * {@see ExtensionRegistry} is #[Internal], so the Compliance module may not
     * import it; the composition root reads it here and passes plain strings.
     *
     * @return list<string>
     */
    private static function activeExtensions(ContainerInterface $container): array
    {
        if (!$container->has(ExtensionRegistry::class)) {
            return [];
        }

        return $container->get(ExtensionRegistry::class)->names();
    }
}
