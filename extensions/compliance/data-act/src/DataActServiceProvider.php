<?php

declare(strict_types=1);

namespace Pulsar\Extension\DataAct;

use Pulsar\Api\Internal;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionConfigRegistry;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\DataAct\Access\DataAccessController;
use Pulsar\Extension\DataAct\Access\ThirdPartyAccessPolicy;
use Pulsar\Extension\DataAct\CloudSwitching\SwitchingAssistant;
use Pulsar\Extension\DataAct\Config\DataActConfig;
use Pulsar\Extension\DataAct\Internal\InMemoryDataPortabilityService;
use Pulsar\Extension\DataAct\Interoperability\InteroperabilityProfile;
use Pulsar\Extension\DataAct\Portability\DataPortabilityService;

/**
 * Wires Data Act compliance services into the container.
 *
 * Production deployments should override DataPortabilityService
 * with an implementation backed by actual application data stores.
 */
#[Internal(reason: 'Data Act service wiring; use interfaces and public classes for API')]
final class DataActServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        $container->bind(DataActConfig::class, static function () use ($container): DataActConfig {
            $configData = $container->has(ExtensionConfigRegistry::class)
                ? $container->get(ExtensionConfigRegistry::class)->section('data_act')
                : [];

            return DataActConfig::fromArray($configData);
        });

        $container->bind(DataPortabilityService::class, static function () use ($container): DataPortabilityService {
            /** @var DataActConfig $config */
            $config = $container->get(DataActConfig::class);

            return new InMemoryDataPortabilityService($config);
        });

        $container->bind(InteroperabilityProfile::class, static function () use ($container): InteroperabilityProfile {
            /** @var DataActConfig $config */
            $config = $container->get(DataActConfig::class);

            return InteroperabilityProfile::forPlatform($config);
        });

        $container->bind(SwitchingAssistant::class, static function () use ($container): SwitchingAssistant {
            /** @var DataActConfig $config */
            $config = $container->get(DataActConfig::class);

            /** @var DataPortabilityService $portability */
            $portability = $container->get(DataPortabilityService::class);

            return new SwitchingAssistant($config, $portability);
        });

        $container->bind(ThirdPartyAccessPolicy::class, static function () use ($container): ThirdPartyAccessPolicy {
            /** @var DataActConfig $config */
            $config = $container->get(DataActConfig::class);

            return new ThirdPartyAccessPolicy($config);
        });

        $container->bind(DataAccessController::class, static function () use ($container): DataAccessController {
            /** @var DataPortabilityService $portability */
            $portability = $container->get(DataPortabilityService::class);

            /** @var ThirdPartyAccessPolicy $policy */
            $policy = $container->get(ThirdPartyAccessPolicy::class);

            return new DataAccessController($portability, $policy);
        });
    }

    /**
     * @return list<string>
     */
    public function provides(): array
    {
        return [
            DataActConfig::class,
            DataPortabilityService::class,
            InteroperabilityProfile::class,
            SwitchingAssistant::class,
            ThirdPartyAccessPolicy::class,
            DataAccessController::class,
        ];
    }
}
