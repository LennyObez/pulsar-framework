<?php

declare(strict_types=1);

namespace Pulsar\Core\Boot;

use Pulsar\Api\Internal;
use Pulsar\Build\BuildArtifactLoader;
use Pulsar\Build\BuildException;
use Pulsar\Build\VerificationStatus;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\ContainerInterface;
use Pulsar\Security\Crypto\HmacInterface;
use Pulsar\Security\Crypto\KeyProviderInterface;
use Throwable;

use const DIRECTORY_SEPARATOR;

/**
 * Verifies build artifacts during kernel boot.
 *
 * In production: fails fast if artifacts are missing, optionally verifies
 * integrity (signature first, then artifact hashes). In development:
 * verification is skipped (artifacts may not exist). Extracted from
 * {@see \Pulsar\Core\Kernel}; runs once at boot, never on the request path.
 */
#[Internal]
final class BuildArtifactVerifier
{
    /**
     * @throws BuildException If required artifacts are missing or integrity check fails
     */
    public static function verify(ContainerInterface $container, ?ConfigManager $configManager): void
    {
        $configPath = $configManager?->configPath();

        if ($configPath === null) {
            return;
        }

        $isProduction = self::isProductionMode($configManager);

        // Only enforce in production mode
        if (!$isProduction) {
            return;
        }

        $cacheDir = $configPath . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'cache';
        $loader = new BuildArtifactLoader($cacheDir);

        // Production mode: require build artifacts
        if (!$loader->hasArtifacts()) {
            throw BuildException::missingArtifacts(['build-manifest.json']);
        }

        // Optional integrity verification
        if (BuildArtifactLoader::isVerificationEnabled()) {
            $manifest = $loader->loadManifest();

            if ($manifest === null) {
                throw BuildException::missingArtifact('build-manifest.json');
            }

            // Verify signature FIRST (if present and crypto services available)
            // This ensures the manifest itself is authentic before trusting its hashes
            if ($manifest->signature !== null
                && $container->has(HmacInterface::class)
                && $container->has(KeyProviderInterface::class)
            ) {
                /** @var HmacInterface $hmac */
                $hmac = $container->get(HmacInterface::class);
                /** @var KeyProviderInterface $keyProvider */
                $keyProvider = $container->get(KeyProviderInterface::class);

                if (!$loader->verifySignature($manifest, $hmac, $keyProvider)) {
                    throw BuildException::signatureVerificationFailed();
                }
            }

            // Then verify artifact hashes against the (now-authenticated) manifest
            $result = $loader->verifyIntegrity($manifest);

            if ($result !== null && !$result->passed) {
                $failed = [];

                foreach ($result->entries as $key => $status) {
                    if ($status !== VerificationStatus::Ok) {
                        $failed[] = $key;
                    }
                }

                throw BuildException::integrityCheckFailedMultiple($failed);
            }
        }
    }

    /**
     * Determine if the application is running in production mode.
     */
    private static function isProductionMode(?ConfigManager $configManager): bool
    {
        try {
            $env = $configManager?->environment();
            $appEnv = $env?->get('APP_ENV') ?? 'production';

            return $appEnv === 'production';
        } catch (Throwable) {
            return false;
        }
    }
}
