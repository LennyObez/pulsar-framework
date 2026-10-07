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
use Pulsar\Security\Crypto\HmacService;
use Pulsar\Security\Crypto\KeyProviderInterface;
use Pulsar\Security\Crypto\MasterKey;
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

            // Verify the signature FIRST, so the manifest's hashes are trusted
            // only once the manifest itself is authenticated. This runs early in
            // boot, before SecurityWiring binds the crypto services, so the
            // verifier bootstraps them from the master key itself when the
            // container has none — keeping signature verification independent of
            // wiring order. A signed manifest is fail-closed: if the key material
            // needed to authenticate it is unavailable, refuse to boot rather than
            // trust the manifest on its hashes alone (which an attacker rewrites).
            if ($manifest->signature !== null) {
                $hmac = $container->has(HmacInterface::class)
                    ? $container->get(HmacInterface::class)
                    : new HmacService();
                $keyProvider = $container->has(KeyProviderInterface::class)
                    ? $container->get(KeyProviderInterface::class)
                    : self::masterKeyFromEnvironment($configManager);

                if (!$hmac instanceof HmacInterface || !$keyProvider instanceof KeyProviderInterface) {
                    throw BuildException::signatureVerificationUnavailable();
                }

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

    /**
     * Build a key provider from the master key in the environment, so manifest
     * signatures can be verified before SecurityWiring runs. Returns null when no
     * (valid) master key is available — the caller fails closed. Signing uses a
     * sub-key derived directly from the master key, so a plain {@see MasterKey}
     * yields the same signing key the wired CompositeKeyProvider would.
     */
    private static function masterKeyFromEnvironment(?ConfigManager $configManager): ?KeyProviderInterface
    {
        try {
            $hex = $configManager?->environment()->get('PULSAR_MASTER_KEY');

            if ($hex === null || $hex === '') {
                return null;
            }

            return MasterKey::fromHex($hex);
        } catch (Throwable) {
            // Unloaded environment or malformed key → cannot verify → fail closed.
            return null;
        }
    }
}
