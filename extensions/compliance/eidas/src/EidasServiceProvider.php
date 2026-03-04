<?php

declare(strict_types=1);

namespace Pulsar\Extension\Eidas;

use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\Eidas\Config\EidasConfig;
use Pulsar\Extension\Eidas\Contracts\DigitalSignatureServiceInterface;
use Pulsar\Extension\Eidas\Contracts\ElectronicSealServiceInterface;
use Pulsar\Extension\Eidas\Contracts\RegisteredDeliveryServiceInterface;
use Pulsar\Extension\Eidas\Contracts\TimestampServiceInterface;
use Pulsar\Extension\Eidas\Internal\Delivery\InMemoryDeliveryService;
use Pulsar\Extension\Eidas\Internal\Seal\HmacSealService;
use Pulsar\Extension\Eidas\Internal\Signature\HmacSignatureService;
use Pulsar\Extension\Eidas\Internal\Timestamp\LocalTimestampService;

/**
 * Service provider for the eIDAS extension.
 */
#[Internal(reason: 'Wiring only; use the public contracts')]
final class EidasServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        // Config
        $container->bind(EidasConfig::class, static function () use ($container): EidasConfig {
            /** @var array<string, mixed> $configData */
            $configData = [];

            if ($container->has('config.eidas')) {
                /** @var array<string, mixed> $configData */
                $configData = $container->get('config.eidas');
            }

            return EidasConfig::fromArray($configData);
        });

        // Signature Service
        $container->bind(DigitalSignatureServiceInterface::class, static function () use ($container): DigitalSignatureServiceInterface {
            /** @var EidasConfig $config */
            $config = $container->get(EidasConfig::class);

            $auditLogger = $container->has(AuditLoggerInterface::class)
                ? $container->get(AuditLoggerInterface::class)
                : null;

            /** @var AuditLoggerInterface|null $auditLogger */

            /** @var DigitalSignatureServiceInterface */
            return match ($config->signatureService) {
                'hmac' => new HmacSignatureService(auditLogger: $auditLogger),
                default => $container->get($config->signatureService),
            };
        });

        // Seal Service
        $container->bind(ElectronicSealServiceInterface::class, static function () use ($container): ElectronicSealServiceInterface {
            /** @var EidasConfig $config */
            $config = $container->get(EidasConfig::class);

            /** @var ElectronicSealServiceInterface */
            return match ($config->sealService) {
                'hmac' => new HmacSealService(),
                default => $container->get($config->sealService),
            };
        });

        // Timestamp Service
        $container->bind(TimestampServiceInterface::class, static function () use ($container): TimestampServiceInterface {
            /** @var EidasConfig $config */
            $config = $container->get(EidasConfig::class);

            /** @var TimestampServiceInterface */
            return match ($config->timestampService) {
                'local' => new LocalTimestampService($config->tsaName),
                default => $container->get($config->timestampService),
            };
        });

        // Delivery Service
        $container->bind(RegisteredDeliveryServiceInterface::class, static function () use ($container): RegisteredDeliveryServiceInterface {
            /** @var EidasConfig $config */
            $config = $container->get(EidasConfig::class);

            /** @var RegisteredDeliveryServiceInterface */
            return match ($config->deliveryService) {
                'memory' => new InMemoryDeliveryService(),
                default => $container->get($config->deliveryService),
            };
        });
    }

    public function provides(): array
    {
        return [
            EidasConfig::class,
            DigitalSignatureServiceInterface::class,
            ElectronicSealServiceInterface::class,
            TimestampServiceInterface::class,
            RegisteredDeliveryServiceInterface::class,
        ];
    }
}
