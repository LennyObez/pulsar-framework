<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dsa;

use Pulsar\Api\Internal;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionConfigRegistry;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\Dsa\Config\DsaConfig;
use Pulsar\Extension\Dsa\ContentModeration\AppealHandler;
use Pulsar\Extension\Dsa\ContentModeration\ModerationLog;
use Pulsar\Extension\Dsa\Internal\InMemoryModerationLog;
use Pulsar\Extension\Dsa\Internal\InMemoryTrustedFlaggerRegistry;
use Pulsar\Extension\Dsa\NoticeAction\NoticeAndActionHandler;
use Pulsar\Extension\Dsa\Transparency\TransparencyReportGenerator;
use Pulsar\Extension\Dsa\TrustedFlagger\TrustedFlaggerRegistry;

/**
 * Wires DSA compliance services into the container.
 *
 * Production deployments should override ModerationLog and
 * TrustedFlaggerRegistry with persistent implementations.
 */
#[Internal(reason: 'DSA service wiring; use interfaces and public classes for API')]
final class DsaServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        $container->bind(DsaConfig::class, static function () use ($container): DsaConfig {
            $configData = $container->has(ExtensionConfigRegistry::class)
                ? $container->get(ExtensionConfigRegistry::class)->section('dsa')
                : [];

            return DsaConfig::fromArray($configData);
        });

        $container->bind(ModerationLog::class, static function (): ModerationLog {
            return new InMemoryModerationLog();
        });

        $container->bind(TrustedFlaggerRegistry::class, static function (): TrustedFlaggerRegistry {
            return new InMemoryTrustedFlaggerRegistry();
        });

        $container->bind(AppealHandler::class, static function () use ($container): AppealHandler {
            /** @var ModerationLog $log */
            $log = $container->get(ModerationLog::class);

            return new AppealHandler($log);
        });

        $container->bind(TransparencyReportGenerator::class, static function () use ($container): TransparencyReportGenerator {
            /** @var ModerationLog $log */
            $log = $container->get(ModerationLog::class);

            /** @var DsaConfig $config */
            $config = $container->get(DsaConfig::class);

            return new TransparencyReportGenerator($log, $config);
        });

        $container->bind(NoticeAndActionHandler::class, static function () use ($container): NoticeAndActionHandler {
            /** @var ModerationLog $log */
            $log = $container->get(ModerationLog::class);

            /** @var TrustedFlaggerRegistry $registry */
            $registry = $container->get(TrustedFlaggerRegistry::class);

            return new NoticeAndActionHandler($log, $registry);
        });
    }

    /**
     * @return list<string>
     */
    public function provides(): array
    {
        return [
            DsaConfig::class,
            ModerationLog::class,
            TrustedFlaggerRegistry::class,
            AppealHandler::class,
            TransparencyReportGenerator::class,
            NoticeAndActionHandler::class,
        ];
    }
}
