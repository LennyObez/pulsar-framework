<?php

declare(strict_types=1);

namespace Pulsar\Deploy\Check;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Deploy\CheckResult;
use Pulsar\Deploy\DeployCheckInterface;
use Pulsar\Deploy\Runtime\FilesystemReaderInterface;
use Pulsar\Deploy\Runtime\PhpRuntimeInterface;

use function array_find;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strtolower;

/**
 * Validates OPcache configuration for the target environment.
 *
 * Checks that OPcache is enabled, revalidation frequency is appropriate,
 * preload is configured, and the preload path is safe.
 */
#[Internal]
final readonly class OpcacheCheck implements DeployCheckInterface
{
    private const string CHECK_NAME = 'opcache';

    /** Directories considered unsafe for executable preload scripts. */
    private const array UNSAFE_PATH_PREFIXES = [
        '/tmp/',
        '/var/tmp/',
        '/var/cache/',
    ];

    /** Windows unsafe prefixes (case-insensitive check). */
    private const array UNSAFE_PATH_PREFIXES_WINDOWS = [
        'c:\\windows\\temp\\',
    ];

    /** Relative unsafe prefixes. */
    private const array UNSAFE_RELATIVE_PREFIXES = [
        'var/cache/',
    ];

    public function __construct(
        private PhpRuntimeInterface $runtime,
        private ?FilesystemReaderInterface $filesystem = null,
    ) {}

    #[Override]
    public function getName(): string
    {
        return self::CHECK_NAME;
    }

    #[Override]
    public function getDescription(): string
    {
        return 'Validates OPcache is enabled and configured for the target environment';
    }

    #[Override]
    public function check(string $environment): CheckResult
    {
        if (!$this->runtime->extensionLoaded('Zend OPcache')) {
            return match ($environment) {
                'production' => CheckResult::error(
                    self::CHECK_NAME,
                    'OPcache extension is not loaded',
                    [
                        'Install and enable the OPcache extension for production performance.',
                        'Add "zend_extension=opcache" to php.ini.',
                    ],
                ),
                'staging' => CheckResult::warning(
                    self::CHECK_NAME,
                    'OPcache extension is not loaded',
                    ['Install the OPcache extension to mirror production behavior.'],
                ),
                default => CheckResult::pass(
                    self::CHECK_NAME,
                    'OPcache not required in local environment',
                ),
            };
        }

        $enabled = (bool) $this->runtime->iniGet('opcache.enable');

        if (!$enabled) {
            return match ($environment) {
                'production' => CheckResult::error(
                    self::CHECK_NAME,
                    'OPcache is installed but not enabled (opcache.enable=0)',
                    ['Set opcache.enable=1 in php.ini for production.'],
                ),
                'staging' => CheckResult::warning(
                    self::CHECK_NAME,
                    'OPcache is installed but not enabled',
                    ['Enable OPcache to mirror production behavior.'],
                ),
                default => CheckResult::pass(
                    self::CHECK_NAME,
                    'OPcache disabled in local environment (acceptable)',
                ),
            };
        }

        if ($environment === 'production' || $environment === 'staging') {
            $revalidateFreq = (int) $this->runtime->iniGet('opcache.revalidate_freq');

            if ($revalidateFreq < 60) {
                return CheckResult::warning(
                    self::CHECK_NAME,
                    sprintf(
                        'OPcache revalidate_freq is %d seconds (recommended >= 60 for %s)',
                        $revalidateFreq,
                        $environment,
                    ),
                    [
                        'Set opcache.revalidate_freq=60 or higher in production.',
                        'Consider opcache.validate_timestamps=0 for immutable deployments.',
                    ],
                );
            }

            // Preload checks
            $preloadResult = $this->checkPreload($environment);

            if ($preloadResult !== null) {
                return $preloadResult;
            }
        }

        return CheckResult::pass(
            self::CHECK_NAME,
            'OPcache is enabled and properly configured',
        );
    }

    /**
     * Check preload configuration for production/staging.
     *
     * @return CheckResult|null Null if preload config is acceptable
     */
    private function checkPreload(string $environment): ?CheckResult
    {
        $preloadPath = $this->runtime->iniGet('opcache.preload');

        if ($preloadPath === false || $preloadPath === '') {
            return CheckResult::warning(
                self::CHECK_NAME,
                'OPcache preloading is not configured',
                [
                    'Generate a preload script: php bin/pulsar preload:dump --output=preload.generated.php',
                    'Set opcache.preload=/path/to/preload.generated.php in php.ini.',
                    'Preloading improves performance by loading hot-path classes at server start.',
                ],
            );
        }

        // Check for relative path
        if (!$this->isAbsolutePath($preloadPath)) {
            return CheckResult::warning(
                self::CHECK_NAME,
                'Preload path is relative — use an absolute path to avoid ambiguity across SAPIs',
                [
                    sprintf('Current path: %s', $preloadPath),
                    'Set opcache.preload to an absolute path (e.g., /var/www/app/preload.generated.php).',
                ],
            );
        }

        // Check for unsafe directories
        $unsafeDir = $this->detectUnsafeDirectory($preloadPath);

        if ($unsafeDir !== null) {
            return CheckResult::warning(
                self::CHECK_NAME,
                sprintf(
                    'Preload path resides in a writable directory (%s). Store preload scripts in an immutable deploy path to prevent RCE.',
                    $unsafeDir,
                ),
                [
                    sprintf('Current path: %s', $preloadPath),
                    'Move preload.generated.php to the project root or another immutable deploy path.',
                    'OPcache preloading executes at server start, before framework defenses.',
                ],
            );
        }

        // Check if the preload file is readable
        if ($this->filesystem !== null && !$this->filesystem->isReadable($preloadPath)) {
            return CheckResult::warning(
                self::CHECK_NAME,
                'Preload path does not exist or is not readable',
                [
                    sprintf('Configured path: %s', $preloadPath),
                    'Ensure the file exists and is readable by the web server user.',
                    'Generate with: php bin/pulsar preload:dump --output=preload.generated.php',
                ],
            );
        }

        // Check preload_user
        $preloadUser = $this->runtime->iniGet('opcache.preload_user');

        if ($preloadUser === false || $preloadUser === '') {
            return CheckResult::warning(
                self::CHECK_NAME,
                'opcache.preload_user is not set',
                [
                    'Set opcache.preload_user to the web server user (e.g., www-data).',
                    'This setting is required when running PHP as root with preloading enabled.',
                ],
            );
        }

        return null;
    }

    /**
     * Check whether a path looks absolute (Unix or Windows).
     */
    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || (isset($path[2]) && $path[1] === ':' && ($path[2] === '\\' || $path[2] === '/'));
    }

    /**
     * Detect if the path resides in a known unsafe directory.
     *
     * @return string|null The matched unsafe prefix, or null if safe
     */
    private function detectUnsafeDirectory(string $path): ?string
    {
        // Check for traversal indicators
        if (str_contains($path, '..')) {
            return '..';
        }

        // Unix unsafe prefixes
        /** @psalm-suppress MixedReturnStatement — Psalm 6.x lacks return-type inference for array_find() */
        return array_find(self::UNSAFE_PATH_PREFIXES, static fn(string $prefix): bool => str_starts_with($path, $prefix))
            // Windows unsafe prefixes (case-insensitive)
            ?? array_find(self::UNSAFE_PATH_PREFIXES_WINDOWS, static fn(string $prefix): bool => str_starts_with(strtolower($path), $prefix))
            // Relative unsafe prefixes (shouldn't appear in absolute paths, but belt-and-suspenders)
            ?? array_find(self::UNSAFE_RELATIVE_PREFIXES, static fn(string $prefix): bool => str_contains($path, $prefix));
    }
}
