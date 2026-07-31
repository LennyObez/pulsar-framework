<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Compliance\ComplianceConfig;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\ComplianceProfile;
use Pulsar\Compliance\Verification\CheckStatus;
use Pulsar\Compliance\Verification\ComplianceVerificationEngine;
use Pulsar\Compliance\Verification\ConflictDetector;
use Pulsar\Compliance\Verification\CustomControlRegistry;
use Pulsar\Compliance\Verification\RuntimeVerifier;
use Pulsar\Compliance\Verification\VerificationConfig;
use Pulsar\Compliance\Verification\VerificationReport;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\ConfigRepository;
use Pulsar\Config\ConnectionConfig;
use Pulsar\Config\DatabaseConfig;
use Pulsar\Config\Exception\ConfigException;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\Driver;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Security\Session\SessionEncryption;

use function array_map;
use function constant;
use function defined;
use function implode;
use function in_array;
use function is_int;
use function is_string;
use function sprintf;
use function strtolower;

/**
 * Runs boot-time compliance verification against the FULLY WIRED container.
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
 * It is registered LAST for the same reason {@see SecurityPostureWiring} is: only a
 * fully wired container reveals which security features are genuinely active and
 * which are inert.
 */
#[Internal(reason: 'Composition root wiring')]
final readonly class ComplianceVerificationWiring implements ServiceWiringInterface
{
    /**
     * `sslmode` values that actually require TLS. Weaker values ("disable",
     * "allow", "prefer") let the connection silently fall back to plaintext.
     *
     * @var list<string>
     */
    private const array TLS_SSL_MODES = ['require', 'required', 'verify-ca', 'verify-full', 'verify_ca', 'verify_full'];

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

        $engine = new ComplianceVerificationEngine(
            profile: $profile,
            runtimeVerifier: $this->runtimeVerifier($container, $repository, $profile),
            conflictDetector: new ConflictDetector(),
            customControlRegistry: new CustomControlRegistry(),
            config: new VerificationConfig(
                enabled: $config->verificationEnabled,
                bootCheck: $config->bootCheck,
                evidenceIntervalSeconds: $config->evidenceInterval,
                strictMode: $config->strictMode,
            ),
            logger: $container->has(LoggerInterface::class)
                ? $container->get(LoggerInterface::class)
                : null,
        );

        // Registered whether or not the boot check runs, so an operator can re-run
        // verification on demand (console command, health endpoint, scheduled job).
        $container->instance(ComplianceVerificationEngine::class, $engine);

        if (!$engine->shouldCheckAtBoot()) {
            return;
        }

        $report = $engine->verify();
        $container->instance(VerificationReport::class, $report);

        $this->reportFailures($container, $report, $profile, $config->strictMode);
    }

    /**
     * Build the runtime verifier from what the container actually holds.
     *
     * A bound service is evidence the feature is live; a config flag only says it
     * was requested. SecurityWiring binds SessionEncryption, MasterKey and
     * AuditLoggerInterface only when the corresponding subsystem really started
     * (which for all three additionally requires a master key), so these are the
     * honest runtime signals.
     */
    private function runtimeVerifier(
        ContainerInterface $container,
        ConfigRepository $repository,
        ComplianceProfile $profile,
    ): RuntimeVerifier {
        return new RuntimeVerifier(
            profile: $profile,
            sessionEncryptionActive: $container->has(SessionEncryption::class),
            masterKeyDerived: $container->has(MasterKey::class),
            auditLogActive: $container->has(AuditLoggerInterface::class),
            dbTlsActive: $this->databaseTlsActive($repository),
        );
    }

    /**
     * Whether every database connection that actually crosses a network is
     * configured for TLS.
     *
     * Reporting a blanket `false` here would be worse than useless: the verifier
     * turns it into a hard failure whenever a framework requires encryption in
     * transit, so under compliance strict mode the boot could never succeed no
     * matter how the operator configured the database. The connection options are
     * therefore inspected for the very settings the verifier's own remediation text
     * asks for (`ssl_mode`/`sslmode`, or the PDO MySQL SSL attributes).
     *
     * SQLite is skipped rather than failed: it is a local file with no transport to
     * encrypt, so demanding TLS of it would be meaningless. A deployment with no
     * network connection at all satisfies the requirement vacuously — nothing
     * travels in the clear.
     */
    private function databaseTlsActive(ConfigRepository $repository): bool
    {
        if (!$repository->has(DatabaseConfig::class)) {
            return true; // no database configured: no unencrypted transport exists
        }

        /** @var DatabaseConfig $database */
        $database = $repository->get(DatabaseConfig::class);

        foreach ($database->connections as $connection) {
            if ($connection->driver === Driver::SQLite) {
                continue;
            }

            if (!self::connectionUsesTls($connection)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Recognize a TLS-enabled connection from its PDO options.
     *
     * Accepts the PostgreSQL/MySQL `sslmode`/`ssl_mode` spellings at a strength of
     * at least "require", and the presence of any PDO MySQL SSL attribute (CA,
     * client cert or key), which cannot be set without TLS being used. The PDO
     * constants are resolved defensively because pdo_mysql may not be loaded.
     */
    private static function connectionUsesTls(ConnectionConfig $connection): bool
    {
        $options = $connection->options;

        foreach (['ssl_mode', 'sslmode'] as $key) {
            /** @var mixed $mode */
            $mode = $options[$key] ?? null;

            if (is_string($mode) && in_array(strtolower($mode), self::TLS_SSL_MODES, true)) {
                return true;
            }
        }

        // PHP 8.5 renamed these to the Pdo\Mysql enum-style constants and deprecated
        // the PDO::MYSQL_ATTR_* spellings. The underlying integer values are
        // unchanged, so resolving the modern names also matches an option array a
        // project wrote with the legacy constants.
        foreach (['Pdo\Mysql::ATTR_SSL_CA', 'Pdo\Mysql::ATTR_SSL_CERT', 'Pdo\Mysql::ATTR_SSL_KEY'] as $constant) {
            if (!defined($constant)) {
                continue;
            }

            /** @var mixed $attribute */
            $attribute = constant($constant);

            if ((is_int($attribute) || is_string($attribute)) && isset($options[$attribute])) {
                return true;
            }
        }

        return false;
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

        /** @var LoggerInterface $logger */
        $logger = $container->get(LoggerInterface::class);

        $frameworks = implode(', ', array_map(
            static fn(ComplianceFramework $f): string => $f->value,
            $profile->enabledFrameworks,
        ));

        foreach ($lines as $line) {
            $logger->warning(sprintf(
                'compliance: boot verification failed — %s (frameworks: %s)',
                $line,
                $frameworks,
            ));
        }
    }
}
