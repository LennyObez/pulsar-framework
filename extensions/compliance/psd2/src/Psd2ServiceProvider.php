<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2;

use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Container\Resolution\TypedServiceResolver;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\Psd2\Config\Psd2Config;
use Pulsar\Extension\Psd2\Contracts\CertificateValidatorInterface;
use Pulsar\Extension\Psd2\Contracts\ScaChallengeStoreInterface;
use Pulsar\Extension\Psd2\Contracts\ScaDynamicLinkingServiceInterface;
use Pulsar\Extension\Psd2\Contracts\TransactionRiskAnalyzerInterface;
use Pulsar\Extension\Psd2\Contracts\VelocityTrackerInterface;
use Pulsar\Extension\Psd2\Exception\Psd2Exception;
use Pulsar\Extension\Psd2\Internal\Certificate\DefaultCertificateValidator;
use Pulsar\Extension\Psd2\Internal\Monitoring\InMemoryVelocityTracker;
use Pulsar\Extension\Psd2\Internal\Monitoring\TransactionRiskAnalyzer;
use Pulsar\Extension\Psd2\Internal\Sca\InMemoryScaChallengeStore;
use Pulsar\Extension\Psd2\Internal\Sca\ScaDynamicLinkingService;
use Pulsar\Extension\Psd2\Middleware\CertificateAuthenticationMiddleware;
use Pulsar\Extension\Psd2\Middleware\ScaRequiredMiddleware;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Security\Crypto\SubKeyId;

/**
 * Service provider for the PSD2 extension.
 */
#[Internal(reason: 'Wiring only; use the public contracts')]
final class Psd2ServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        // Config
        $container->bind(Psd2Config::class, static function () use ($container): Psd2Config {
            /** @var array<string, mixed> $configData */
            $configData = [];

            if ($container->has('config.psd2')) {
                /** @var array<string, mixed> $configData */
                $configData = $container->get('config.psd2');
            }

            return Psd2Config::fromArray($configData);
        });

        // SCA Challenge Store
        $container->bind(ScaChallengeStoreInterface::class, static function () use ($container): ScaChallengeStoreInterface {
            /** @var Psd2Config $config */
            $config = $container->get(Psd2Config::class);

            /** @var ScaChallengeStoreInterface */
            return match ($config->sca->challengeStore) {
                'memory' => new InMemoryScaChallengeStore(),
                default => TypedServiceResolver::resolve(
                    $container,
                    $config->sca->challengeStore,
                    ScaChallengeStoreInterface::class,
                    'psd2.sca.challenge_store',
                ),
            };
        });

        // Velocity Tracker
        $container->bind(VelocityTrackerInterface::class, static function () use ($container): VelocityTrackerInterface {
            /** @var Psd2Config $config */
            $config = $container->get(Psd2Config::class);

            /** @var VelocityTrackerInterface */
            return match ($config->risk->velocityTracker) {
                'memory' => new InMemoryVelocityTracker(),
                default => TypedServiceResolver::resolve(
                    $container,
                    $config->risk->velocityTracker,
                    VelocityTrackerInterface::class,
                    'psd2.risk.velocity_tracker',
                ),
            };
        });

        // SCA Dynamic Linking Service
        $container->bind(ScaDynamicLinkingServiceInterface::class, static function () use ($container): ScaDynamicLinkingServiceInterface {
            /** @var Psd2Config $config */
            $config = $container->get(Psd2Config::class);

            /** @var ScaChallengeStoreInterface $store */
            $store = $container->get(ScaChallengeStoreInterface::class);

            $auditLogger = $container->has(AuditLoggerInterface::class)
                ? $container->get(AuditLoggerInterface::class)
                : null;

            /** @var AuditLoggerInterface|null $auditLogger */

            // Fail closed: the dynamic-linking code is a keyed MAC, so without a
            // master key to derive the secret from there is no secure code to mint.
            if (!$container->has(MasterKey::class)) {
                throw Psd2Exception::scaSecretUnavailable();
            }

            /** @var MasterKey $masterKey */
            $masterKey = $container->get(MasterKey::class);
            $secretKey = $masterKey->deriveSubKey(
                SubKeyId::Psd2ScaDynamicLinking->value,
                'psd2-sca-dynamic-linking',
            );

            return new ScaDynamicLinkingService($store, $config->sca, $secretKey, $auditLogger);
        });

        // Transaction Risk Analyzer
        $container->bind(TransactionRiskAnalyzerInterface::class, static function () use ($container): TransactionRiskAnalyzerInterface {
            /** @var Psd2Config $config */
            $config = $container->get(Psd2Config::class);

            /** @var VelocityTrackerInterface $tracker */
            $tracker = $container->get(VelocityTrackerInterface::class);

            $auditLogger = $container->has(AuditLoggerInterface::class)
                ? $container->get(AuditLoggerInterface::class)
                : null;

            /** @var AuditLoggerInterface|null $auditLogger */

            return new TransactionRiskAnalyzer($tracker, $config->risk, $auditLogger);
        });

        // Certificate Validator
        $container->bind(CertificateValidatorInterface::class, static function () use ($container): CertificateValidatorInterface {
            /** @var Psd2Config $config */
            $config = $container->get(Psd2Config::class);

            $auditLogger = $container->has(AuditLoggerInterface::class)
                ? $container->get(AuditLoggerInterface::class)
                : null;

            /** @var AuditLoggerInterface|null $auditLogger */

            return new DefaultCertificateValidator($config->certificate, auditLogger: $auditLogger);
        });

        // Middleware
        $container->bind(ScaRequiredMiddleware::class, ScaRequiredMiddleware::class);
        $container->bind(CertificateAuthenticationMiddleware::class, CertificateAuthenticationMiddleware::class);
    }

    public function provides(): array
    {
        return [
            Psd2Config::class,
            ScaChallengeStoreInterface::class,
            VelocityTrackerInterface::class,
            ScaDynamicLinkingServiceInterface::class,
            TransactionRiskAnalyzerInterface::class,
            CertificateValidatorInterface::class,
            ScaRequiredMiddleware::class,
            CertificateAuthenticationMiddleware::class,
        ];
    }
}
