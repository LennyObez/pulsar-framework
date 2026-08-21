<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Compliance\ComplianceConfig;
use Pulsar\Compliance\ComplianceConstraints;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\ComplianceProfile;
use Pulsar\Compliance\ComplianceProfileResolver;
use Pulsar\Compliance\Evidence\DatabaseTlsObserver;
use Pulsar\Compliance\Evidence\EvidenceStoreInterface;
use Pulsar\Compliance\Evidence\FileEvidenceStore;
use Pulsar\Compliance\Evidence\InMemoryEvidenceStore;
use Pulsar\Compliance\Verification\BreachNotificationCheck;
use Pulsar\Compliance\Verification\CheckStatus;
use Pulsar\Compliance\Verification\ComplianceVerificationEngine;
use Pulsar\Compliance\Verification\ConflictDetector;
use Pulsar\Compliance\Verification\CustomControlRegistry;
use Pulsar\Compliance\Verification\EvidenceChain;
use Pulsar\Compliance\Verification\EvidenceCollectionJob;
use Pulsar\Compliance\Verification\PasswordPolicyCheck;
use Pulsar\Compliance\Verification\RuntimeVerifier;
use Pulsar\Compliance\Verification\VerificationConfig;
use Pulsar\Compliance\Verification\VerificationReport;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\ConfigRepository;
use Pulsar\Config\DatabaseConfig;
use Pulsar\Config\Exception\ConfigException;
use Pulsar\Config\ObservabilityConfig;
use Pulsar\Config\SchedulerConfig;
use Pulsar\Container\ContainerInterface;
use Pulsar\Filesystem\WritablePathGuard;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;
use Pulsar\Scheduler\Exception\SchedulerException;
use Pulsar\Scheduler\JobRegistry;
use Pulsar\Security\Crypto\CipherSuiteInterface;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Security\Crypto\SubKeyId;
use Pulsar\Security\Incident\IncidentReporterInterface;
use Pulsar\Security\Session\SessionEncryption;
use SodiumException;

use function array_map;
use function dirname;
use function implode;
use function sprintf;

use const DIRECTORY_SEPARATOR;

/**
 * Runs boot-time compliance verification against the FULLY WIRED container, and
 * puts the evidence trail the verification produces on the interval configuration
 * asks for.
 *
 * {@see ComplianceWiring} tightens what configuration can express. Several profile
 * requirements cannot be satisfied by tightening a config value at all:
 *
 *  - tamper-evident audit depends on PULSAR_MASTER_KEY being present, which no
 *    config file can supply;
 *  - encryption at rest is only truly active once a session encrypter was built;
 *  - data retention is deliberately NOT tightened, because the profile models a
 *    keep-longer floor while GDPR storage limitation wants the opposite — so it is
 *    verified and reported rather than silently "enforced" into a violation;
 *  - breach notification and consent have no configuration surface at all.
 *
 * Reporting those honestly is the job of this wiring: it observes what actually
 * got wired (a bound SessionEncryption, MasterKey or AuditLoggerInterface is proof
 * the feature is live — a config flag is not) and turns the verifier's findings
 * into boot warnings, or refuses the boot when compliance strict mode is on.
 *
 * Three seams on the engine used to be constructed empty here, which meant three
 * controls that could not run:
 *
 *  - the EVIDENCE CHAIN was never passed, so `recordEvidence()` returned at its
 *    first line on every run and the deployment's tamper-evident evidence trail
 *    was an empty set. It is built now, keyed from the master key, over a durable
 *    store — see {@see self::evidenceChain()};
 *  - the CUSTOM CONTROL REGISTRY was a fresh empty instance, so `verifyAll()`
 *    returned `[]` unconditionally. It is resolved from the container, where
 *    {@see ComplianceWiring} binds it early enough for anything to register into;
 *  - the PLUGGABLE CHECKS array was never populated at all, so
 *    {@see \Pulsar\Compliance\Verification\ComplianceCheckInterface} was an
 *    extension point with no framework implementation and no framework caller.
 *    Two checks live there now, and each reads a profile field that no wiring, no
 *    validator and no reporter had read: password minimum and breach-notification
 *    deadline.
 *
 * And `verification.evidence_interval` — a number in `config/compliance.php` that
 * no scheduler read — now drives {@see EvidenceCollectionJob}.
 *
 * It is registered LAST for the same reason {@see SecurityPostureWiring} is: only a
 * fully wired container reveals which security features are genuinely active and
 * which are inert.
 */
#[Internal(reason: 'Composition root wiring')]
final readonly class ComplianceVerificationWiring implements ServiceWiringInterface
{
    /**
     * KDF context for the evidence chain HMAC (exactly 8 bytes, as libsodium
     * requires).
     */
    private const string EVIDENCE_KDF_CONTEXT = 'cmp_evid';

    /** File the durable evidence store appends to, beside the audit trail. */
    private const string EVIDENCE_FILE = 'compliance-evidence.jsonl';

    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        $repository = $configManager->repository();

        if (!$repository->has(ComplianceConfig::class) || !$container->has(ComplianceProfile::class)) {
            return;
        }

        /** @var ComplianceConfig $config */
        $config = $repository->get(ComplianceConfig::class);

        // No framework enabled means the deployment opted out of compliance; and an
        // operator who turned verification off is not second-guessed.
        if ($config->enabledFrameworks === [] || !$config->verificationEnabled) {
            return;
        }

        /** @var ComplianceProfile $profile */
        $profile = $container->get(ComplianceProfile::class);

        $constraints = new ComplianceProfileResolver()->constraints($config->enabledFrameworks);
        $evidenceChain = $this->evidenceChain($container, $repository);

        $engine = new ComplianceVerificationEngine(
            profile: $profile,
            runtimeVerifier: $this->runtimeVerifier($container, $repository, $profile),
            conflictDetector: new ConflictDetector(),
            customControlRegistry: $this->customControlRegistry($container),
            config: new VerificationConfig(
                enabled: $config->verificationEnabled,
                bootCheck: $config->bootCheck,
                evidenceIntervalSeconds: $config->evidenceInterval,
                strictMode: $config->strictMode,
            ),
            evidenceChain: $evidenceChain,
            checks: [
                new PasswordPolicyCheck($profile, $constraints->passwordMinLength),
                $this->breachNotificationCheck($container, $profile, $constraints),
            ],
            logger: $container->has(LoggerInterface::class)
                ? $container->get(LoggerInterface::class)
                : null,
        );

        // Registered whether or not the boot check runs, so an operator can re-run
        // verification on demand (console command, health endpoint, scheduled job).
        $container->instance(ComplianceVerificationEngine::class, $engine);

        $this->scheduleEvidenceCollection($container, $engine, $config->evidenceInterval);

        if (!$engine->shouldCheckAtBoot()) {
            return;
        }

        // withoutEvidenceRecording(), and the call is load-bearing. Under PHP-FPM
        // the kernel boots once per REQUEST, so a boot check that recorded would
        // append a signed evidence record per request. Evidence is collected on the
        // configured interval by EvidenceCollectionJob; the boot check is a
        // fail-fast configuration check and nothing more.
        $report = $engine->withoutEvidenceRecording()->verify();
        $container->instance(VerificationReport::class, $report);

        $this->reportFailures($container, $report, $profile, $config->strictMode);
    }

    /**
     * The evidence chain, or null when the deployment cannot have a tamper-evident
     * one.
     *
     * A chain needs a key, and the only key the framework has is the master key.
     * Without PULSAR_MASTER_KEY there is nothing to sign records with, and a chain
     * keyed on a constant would be a chain anybody can forge — so the honest answer
     * is no chain, said out loud in the boot log rather than by silently recording
     * unsigned records that {@see EvidenceChain::verifyChain()} would later report
     * as broken.
     *
     * The store is durable wherever a durable security record already lives, for
     * the reason {@see \Pulsar\Security\Incident\FileIncidentReporter} is the
     * default incident register: each record chains to its predecessor's signature,
     * and predecessors held in process memory are gone before the next record is
     * written, so an in-memory chain is a chain of length one, forever. In-memory
     * remains the answer for a deployment with audit logging switched off, which
     * has asked for no durable security record at all.
     */
    private function evidenceChain(
        ContainerInterface $container,
        ConfigRepository $repository,
    ): ?EvidenceChain {
        $store = $this->evidenceStore($container, $repository);

        $container->instance(EvidenceStoreInterface::class, $store);
        $container->instance($store::class, $store);

        if (!$container->has(MasterKey::class)) {
            $this->warn(
                $container,
                'compliance: no master key is in service, so no compliance evidence chain was '
                . 'built and verification runs leave no tamper-evident record. Set '
                . 'PULSAR_MASTER_KEY to 64 hex characters decoding to 32 bytes.',
            );

            return null;
        }

        /** @var MasterKey $masterKey */
        $masterKey = $container->get(MasterKey::class);

        try {
            $chain = new EvidenceChain(
                $store,
                $masterKey->deriveSubKey(SubKeyId::ComplianceEvidenceChain->value, self::EVIDENCE_KDF_CONTEXT),
            );
        } catch (SodiumException $failure) {
            // The genesis signature is computed in the constructor, so a libsodium
            // that cannot HMAC fails here rather than at the first record. Boot
            // continues without a chain; RuntimeVerifier reports the same libsodium
            // through runtime.sodium_extension.
            $this->warn($container, sprintf(
                'compliance: the evidence chain could not be keyed on this runtime (%s), so '
                . 'verification runs leave no tamper-evident record.',
                $failure->getMessage(),
            ));

            return null;
        }

        $container->instance(EvidenceChain::class, $chain);

        return $chain;
    }

    /**
     * A durable store beside the audit trail, or in-memory when there is no audit
     * trail to sit beside.
     *
     * An operator who has already chosen a writable location for the audit log has
     * chosen one for the evidence register too; it goes through
     * {@see WritablePathGuard} for the same reason the audit sink does, because a
     * compliance evidence file written inside the document root is served to
     * anyone who asks for it.
     */
    private function evidenceStore(
        ContainerInterface $container,
        ConfigRepository $repository,
    ): EvidenceStoreInterface {
        if ($container->has(EvidenceStoreInterface::class)) {
            /** @var EvidenceStoreInterface $bound */
            $bound = $container->get(EvidenceStoreInterface::class);

            return $bound;
        }

        if (!$repository->has(ObservabilityConfig::class)) {
            return new InMemoryEvidenceStore();
        }

        /** @var ObservabilityConfig $observability */
        $observability = $repository->get(ObservabilityConfig::class);

        if (!$observability->audit->enabled) {
            return new InMemoryEvidenceStore();
        }

        $auditLog = WritablePathGuard::resolveState(
            $observability->audit->logPath,
            'observability.audit.log_path',
        );

        return new FileEvidenceStore(dirname($auditLog) . DIRECTORY_SEPARATOR . self::EVIDENCE_FILE);
    }

    /**
     * The registry the application registers custom controls into.
     *
     * Resolved, never constructed. {@see ComplianceWiring} binds it before anything
     * else compliance-related runs; constructing a private one here is what made
     * `CustomControlRegistry::verifyAll()` return `[]` on every boot no matter what
     * the application did. The fallback covers only the case where this wiring is
     * driven directly in a test harness without ComplianceWiring.
     */
    private function customControlRegistry(ContainerInterface $container): CustomControlRegistry
    {
        if (!$container->has(CustomControlRegistry::class)) {
            $registry = new CustomControlRegistry();
            $container->instance(CustomControlRegistry::class, $registry);

            return $registry;
        }

        /** @var CustomControlRegistry $registry */
        $registry = $container->get(CustomControlRegistry::class);

        return $registry;
    }

    /**
     * Measure the breach-notification deadline against the register the deployment
     * actually keeps.
     *
     * The register is passed as an OBJECT rather than a `has()` boolean for the
     * reason ADR-0045 gives and {@see RuntimeVerifier} follows: the check has to
     * read report timestamps out of it, and a boolean is a verdict somebody else
     * already reached.
     */
    private function breachNotificationCheck(
        ContainerInterface $container,
        ComplianceProfile $profile,
        ComplianceConstraints $constraints,
    ): BreachNotificationCheck {
        return new BreachNotificationCheck(
            profile: $profile,
            constrained: $constraints->breachNotificationHours,
            register: $container->has(IncidentReporterInterface::class)
                ? $container->get(IncidentReporterInterface::class)
                : null,
        );
    }

    /**
     * Put `verification.evidence_interval` on the scheduler.
     *
     * The setting was read into {@see VerificationConfig::$evidenceIntervalSeconds}
     * and then consulted by nothing: no job existed, so no deployment collected
     * compliance evidence on any interval. This is the registration that makes the
     * number mean something.
     *
     * Silent when the scheduler is off. That is not the defect returning — the job
     * would have nothing to run it — and warning about it on every request would
     * train an operator to ignore the log. `pulsar scheduler:list` is where the
     * question "is evidence being collected?" gets an answer.
     */
    private function scheduleEvidenceCollection(
        ContainerInterface $container,
        ComplianceVerificationEngine $engine,
        int $intervalSeconds,
    ): void {
        if (!$container->has(JobRegistry::class) || !$container->has(EvidenceStoreInterface::class)) {
            return;
        }

        /** @var JobRegistry $registry */
        $registry = $container->get(JobRegistry::class);
        /** @var EvidenceStoreInterface $store */
        $store = $container->get(EvidenceStoreInterface::class);

        $timezone = $container->has(SchedulerConfig::class)
            ? $container->get(SchedulerConfig::class)->timezone
            : 'UTC';

        try {
            $registry->register(new EvidenceCollectionJob(
                engine: $engine,
                store: $store,
                intervalSeconds: $intervalSeconds,
                schedule: EvidenceCollectionJob::scheduleFor($intervalSeconds, $timezone),
            ));
        } catch (SchedulerException) {
            // An application that registered its own job under this name keeps it.
        }
    }

    /**
     * Build the runtime verifier from what the container actually holds.
     *
     * A bound service is evidence the feature is live; a config flag only says it
     * was requested. SecurityWiring binds SessionEncryption, MasterKey and
     * AuditLoggerInterface only when the corresponding subsystem really started
     * (which for all three additionally requires a master key), so these are the
     * honest runtime signals.
     *
     * Two of them are handed over as OBJECTS rather than as `has()` booleans, and
     * that is the correction rather than a refinement. `has(MasterKey::class)` was
     * feeding a check that reported "Master key uses proper KDF derivation" — a
     * statement about the KDF, made from the fact that a hex string had decoded.
     * `CipherSuiteInterface` was not passed at all, so the FIPS check graded the
     * platform's catalogue of available algorithms instead of the one the
     * deployment encrypts with. The verifier now derives from the key and reads
     * the suite; neither verdict can be reached from a binding lookup any more.
     */
    private function runtimeVerifier(
        ContainerInterface $container,
        ConfigRepository $repository,
        ComplianceProfile $profile,
    ): RuntimeVerifier {
        return new RuntimeVerifier(
            profile: $profile,
            sessionEncryptionActive: $container->has(SessionEncryption::class),
            masterKey: $container->has(MasterKey::class)
                ? $container->get(MasterKey::class)
                : null,
            auditLogActive: $container->has(AuditLoggerInterface::class),
            dbTlsActive: $this->databaseTlsActive($repository),
            activeCipherSuite: $container->has(CipherSuiteInterface::class)
                ? $container->get(CipherSuiteInterface::class)
                : null,
        );
    }

    /**
     * Whether every database connection that actually crosses a network is
     * configured for TLS.
     *
     * Reporting a blanket `false` here would be worse than useless: the verifier
     * turns it into a hard failure whenever a framework requires encryption in
     * transit, so under compliance strict mode the boot could never succeed no
     * matter how the operator configured the database.
     *
     * The inspection itself is {@see DatabaseTlsObserver}'s and is called rather
     * than restated. This wiring and {@see ComplianceCatalogWiring} report on the
     * same connections to the same operator, and while each carried its own copy of
     * the rules the two had already begun to diverge — one docblock claimed the
     * `sslmode` option was honoured on MySQL, which it never is.
     */
    private function databaseTlsActive(ConfigRepository $repository): bool
    {
        if (!$repository->has(DatabaseConfig::class)) {
            return true; // no database configured: no unencrypted transport exists
        }

        /** @var DatabaseConfig $database */
        $database = $repository->get(DatabaseConfig::class);

        return new DatabaseTlsObserver()->configuredForTls($database);
    }

    /**
     * Turn failed checks into operator-facing output: a refused boot under strict
     * mode, warnings otherwise. A passing report is deliberately silent.
     */
    private function reportFailures(
        ContainerInterface $container,
        VerificationReport $report,
        ComplianceProfile $profile,
        bool $strictMode,
    ): void {
        $failures = $report->byStatus(CheckStatus::Fail);

        if ($failures === []) {
            return;
        }

        $lines = array_map(
            static fn(object $result): string => sprintf('%s: %s', $result->checkId, $result->message),
            $failures,
        );

        if ($strictMode) {
            throw ConfigException::complianceVerificationFailed($lines);
        }

        if (!$container->has(LoggerInterface::class)) {
            return;
        }

        $frameworks = implode(', ', array_map(
            static fn(ComplianceFramework $f): string => $f->value,
            $profile->enabledFrameworks,
        ));

        foreach ($lines as $line) {
            $this->warn($container, sprintf(
                'compliance: boot verification failed — %s (frameworks: %s)',
                $line,
                $frameworks,
            ));
        }
    }

    /**
     * Emit an operator-facing boot warning when a logger is bound. A no-op
     * otherwise — this wiring runs long after LoggingWiring, so one normally is.
     */
    private function warn(ContainerInterface $container, string $message): void
    {
        if (!$container->has(LoggerInterface::class)) {
            return;
        }

        /** @var LoggerInterface $logger */
        $logger = $container->get(LoggerInterface::class);
        $logger->warning($message);
    }
}
