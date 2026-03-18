<?php

declare(strict_types=1);

namespace Pulsar\Deploy\Check;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Container\ContainerInterface;
use Pulsar\Deploy\CheckResult;
use Pulsar\Deploy\DeployCheckInterface;
use Pulsar\Extension\Eidas\Config\EidasConfig;

use function class_exists;
use function in_array;

/**
 * Validates that the eIDAS extension is configured with production-grade
 * trust services in staging/production environments.
 *
 * EIDAS-DEFAULT (external audit): the EidasConfig defaults (`hmac`, `local`,
 * `memory`) are explicit dev/test sentinels that do NOT meet eIDAS Article
 * 26–34 requirements for qualified electronic signatures, qualified
 * timestamps, or qualified preservation. Deploying them to production
 * silently breaks every compliance claim the extension makes.
 *
 * The gate is permissive when the extension is not installed (no
 * EidasConfig in the container) so applications that do not use eIDAS
 * are unaffected.
 */
#[Internal]
final readonly class EidasProductionReadinessCheck implements DeployCheckInterface
{
    private const string CHECK_NAME = 'eidas-production-readiness';

    public function __construct(
        private ContainerInterface $container,
    ) {}

    #[Override]
    public function getName(): string
    {
        return self::CHECK_NAME;
    }

    #[Override]
    public function getDescription(): string
    {
        return 'Validates eIDAS trust services are qualified in staging/production';
    }

    #[Override]
    public function check(string $environment): CheckResult
    {
        if ($environment === 'local') {
            return CheckResult::pass(
                self::CHECK_NAME,
                'eIDAS production readiness check is skipped in local environment',
            );
        }

        if (!class_exists(EidasConfig::class)) {
            return CheckResult::pass(
                self::CHECK_NAME,
                'eIDAS extension is not installed; check skipped',
            );
        }

        if (!$this->container->has(EidasConfig::class)) {
            return CheckResult::pass(
                self::CHECK_NAME,
                'EidasConfig is not bound; eIDAS features are not active',
            );
        }

        /** @var EidasConfig $config */
        $config = $this->container->get(EidasConfig::class);
        $offenders = [];

        if (in_array($config->signatureService, EidasConfig::DEV_TEST_SIGNATURE_SERVICES, true)) {
            $offenders[] = sprintf('signatureService=%s (dev/test placeholder; bind a QSeal/QES provider)', $config->signatureService);
        }

        if (in_array($config->sealService, EidasConfig::DEV_TEST_SEAL_SERVICES, true)) {
            $offenders[] = sprintf('sealService=%s (dev/test placeholder; bind a QSeal provider)', $config->sealService);
        }

        if (in_array($config->timestampService, EidasConfig::DEV_TEST_TIMESTAMP_SERVICES, true)) {
            $offenders[] = sprintf('timestampService=%s (dev/test placeholder; bind a QTSA provider)', $config->timestampService);
        }

        if (in_array($config->deliveryService, EidasConfig::DEV_TEST_DELIVERY_SERVICES, true)) {
            $offenders[] = sprintf('deliveryService=%s (dev/test placeholder; bind a persistent delivery provider)', $config->deliveryService);
        }

        if ($offenders === []) {
            return CheckResult::pass(
                self::CHECK_NAME,
                'EidasConfig is wired to production-grade providers',
            );
        }

        return CheckResult::error(
            self::CHECK_NAME,
            sprintf('EidasConfig still uses dev/test defaults in %s: %s', $environment, implode('; ', $offenders)),
            [
                'Configure qualified trust service providers in config/eidas.php before deploying.',
                'See docs/compliance/eidas.md for the list of qualified TSP/QTSA/QSeal/QES providers',
                'integrated by Pulsar adapters.',
            ],
        );
    }
}
