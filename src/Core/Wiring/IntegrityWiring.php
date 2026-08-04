<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use JsonException;
use Pulsar\Api\Internal;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\IntegrityConfig;
use Pulsar\Container\ContainerInterface;
use Pulsar\Deploy\Check\IntegrityCheck;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Integrity\Exception\IntegrityException;
use Pulsar\Integrity\IntegrityManifest;
use Pulsar\Integrity\IntegrityPolicy;
use Pulsar\Integrity\ManifestBuilder;
use Pulsar\Integrity\ManifestBuilderInterface;
use Pulsar\Integrity\ManifestFormat;
use Pulsar\Integrity\ManifestSigner;
use Pulsar\Integrity\ManifestSignerInterface;
use Pulsar\Integrity\ManifestVerifier;
use Pulsar\Integrity\ManifestVerifierInterface;
use Pulsar\Routing\Router;
use Pulsar\Security\Crypto\HmacInterface;
use Pulsar\Security\Crypto\MasterKey;
use SodiumException;

use function dirname;
use function file_get_contents;
use function is_file;
use function str_replace;

use const DIRECTORY_SEPARATOR;

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

        // Builder, verifier and the deploy check all resolve manifest paths
        // against the project root.
        $basePath = $configManager->configPath() !== null
            ? dirname($configManager->configPath())
            : '.';

        if (!$integrityConfig->enabled) {
            // The deploy check still has to exist so it can fail the gate on a
            // production deployment that turned integrity off.
            $container->instance(
                IntegrityCheck::class,
                new IntegrityCheck($integrityConfig, null, null, $basePath),
            );

            return;
        }

        // Policy
        $policy = IntegrityPolicy::fromConfig($integrityConfig);
        $container->instance(IntegrityPolicy::class, $policy);

        $builder = new ManifestBuilder($basePath);
        $container->instance(ManifestBuilder::class, $builder);
        $container->instance(ManifestBuilderInterface::class, $builder);

        $verifier = new ManifestVerifier($basePath);
        $container->instance(ManifestVerifier::class, $verifier);
        $container->instance(ManifestVerifierInterface::class, $verifier);

        // Signer (requires MasterKey)
        $signer = null;

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
                // Signing key derivation failed: skip signer registration
                $signer = null;
            }
        }

        // The deploy check is composed here rather than in DeployWiring because
        // this is where the verifier, the signer and the base path exist.
        $container->instance(
            IntegrityCheck::class,
            new IntegrityCheck($integrityConfig, $verifier, $signer, $basePath),
        );

        // The manifest itself, for consumers that verify outside the deploy gate
        // — the health dashboard among them. Bound lazily: parsing a few thousand
        // entries is not worth doing on a boot that never asks for it. Bound only
        // when a signer exists, because an unauthenticated manifest is worth
        // nothing and resolving to null is what tells callers to report failure.
        if ($signer !== null) {
            $manifestPath = $basePath
                . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $integrityConfig->manifestPath);

            $container->bind(
                IntegrityManifest::class,
                static fn(): IntegrityManifest => self::loadSignedManifest($manifestPath, $signer),
            );
        }
    }

    /**
     * Read, parse and authenticate the manifest at the given path.
     *
     * @throws IntegrityException If it is absent, unreadable, corrupt, unsigned,
     *         or signed with a key other than this deployment's.
     */
    private static function loadSignedManifest(string $path, ManifestSignerInterface $signer): IntegrityManifest
    {
        if (!is_file($path)) {
            throw IntegrityException::manifestNotFound($path);
        }

        $json = file_get_contents($path);

        if ($json === false) {
            throw IntegrityException::manifestCorrupted($path, 'the file cannot be read');
        }

        $manifest = ManifestFormat::fromJson($json);

        try {
            $authentic = $signer->verify($manifest);
        } catch (JsonException | SodiumException $e) {
            throw IntegrityException::manifestCorrupted($path, $e->getMessage());
        }

        if (!$authentic) {
            throw IntegrityException::signatureInvalid();
        }

        return $manifest;
    }
}
