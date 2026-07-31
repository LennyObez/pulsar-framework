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
use Pulsar\Config\Exception\ConfigException;
use Pulsar\Container\ContainerInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Security\Session\SessionEncryption;

use function array_map;
use function implode;
use function sprintf;

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
            runtimeVerifier: $this->runtimeVerifier($container, $profile),
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
    private function runtimeVerifier(ContainerInterface $container, ComplianceProfile $profile): RuntimeVerifier
    {
        return new RuntimeVerifier(
            profile: $profile,
            sessionEncryptionActive: $container->has(SessionEncryption::class),
            masterKeyDerived: $container->has(MasterKey::class),
            auditLogActive: $container->has(AuditLoggerInterface::class),
            // No config or runtime surface models database TLS today, so claiming
            // it is active would be a false assurance.
            dbTlsActive: false,
        );
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
