<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Pulsar\Api\Internal;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\IntegrityConfig;
use Pulsar\Container\ContainerInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Integrity\IntegrityPolicy;
use Pulsar\Integrity\ManifestBuilder;
use Pulsar\Integrity\ManifestBuilderInterface;
use Pulsar\Integrity\ManifestSigner;
use Pulsar\Integrity\ManifestSignerInterface;
use Pulsar\Integrity\ManifestVerifier;
use Pulsar\Integrity\ManifestVerifierInterface;
use Pulsar\Routing\Router;
use Pulsar\Security\Crypto\HmacInterface;
use Pulsar\Security\Crypto\MasterKey;
use SodiumException;

use function dirname;

#[Internal]
final readonly class IntegrityWiring implements ServiceWiringInterface
{
    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        $repository = $configManager->repository();

        if (!$repository->has(IntegrityConfig::class)) {
            return;
        }

        /** @var IntegrityConfig $integrityConfig */
        $integrityConfig = $repository->get(IntegrityConfig::class);
        $container->instance(IntegrityConfig::class, $integrityConfig);

        if (!$integrityConfig->enabled) {
            return;
        }

        // Policy
        $policy = IntegrityPolicy::fromConfig($integrityConfig);
        $container->instance(IntegrityPolicy::class, $policy);

        // Builder and verifier need a base path
        $basePath = $configManager->configPath() !== null
            ? dirname($configManager->configPath())
            : '.';

        $builder = new ManifestBuilder($basePath);
        $container->instance(ManifestBuilder::class, $builder);
        $container->instance(ManifestBuilderInterface::class, $builder);

        $verifier = new ManifestVerifier($basePath);
        $container->instance(ManifestVerifier::class, $verifier);
        $container->instance(ManifestVerifierInterface::class, $verifier);

        // Signer (requires MasterKey)
        if ($container->has(MasterKey::class)) {
            /** @var MasterKey $masterKey */
            $masterKey = $container->get(MasterKey::class);

            try {
                /** @var HmacInterface $hmacService */
                $hmacService = $container->get(HmacInterface::class);
                $signer = new ManifestSigner($hmacService, $masterKey);
                $container->instance(ManifestSigner::class, $signer);
                $container->instance(ManifestSignerInterface::class, $signer);
            } catch (SodiumException) {
                // Signing key derivation failed — skip signer registration
            }
        }
    }
}
