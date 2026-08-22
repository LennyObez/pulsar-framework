<?php

declare(strict_types=1);

namespace Pulsar\Deploy\Check;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Deploy\CheckResult;
use Pulsar\Deploy\DeployCheckInterface;
use Pulsar\Deploy\Runtime\PhpRuntimeInterface;

use function in_array;
use function is_numeric;
use function sprintf;
use function strtolower;

/**
 * Validates JIT compilation readiness for the target environment.
 *
 * JIT is an optional performance lever, not a hard requirement.
 * All severities are advisory.
 */
#[Internal]
final readonly class JitCheck implements DeployCheckInterface
{
    private const string CHECK_NAME = 'jit';

    /** Minimum recommended JIT buffer size in megabytes. */
    private const int MIN_BUFFER_MB = 64;

    /** Values that indicate JIT is disabled. */
    private const array DISABLED_VALUES = ['', '0', 'off', 'disable', 'disabled'];

    public function __construct(
        private PhpRuntimeInterface $runtime,
    ) {}

    #[Override]
    public function getName(): string
    {
        return self::CHECK_NAME;
    }

    #[Override]
    public function getDescription(): string
    {
        return 'Validates JIT compilation is enabled and configured for the target environment';
    }

    #[Override]
    public function check(string $environment): CheckResult
    {
        // JIT is only advisory for non-local environments
        if ($environment !== 'production' && $environment !== 'staging') {
            return CheckResult::pass(
                self::CHECK_NAME,
                'JIT configuration is not checked in local environment',
            );
        }

        if (!$this->runtime->extensionLoaded('Zend OPcache')) {
            return CheckResult::warning(
                self::CHECK_NAME,
                'OPcache extension is not loaded (required for JIT)',
                [
                    'Install and enable the OPcache extension.',
                    'JIT compilation requires OPcache as a prerequisite.',
                ],
            );
        }

        if (!$this->isOpcacheEnabled()) {
            return CheckResult::warning(
                self::CHECK_NAME,
                'OPcache is not enabled (required for JIT)',
                ['Enable OPcache before configuring JIT.'],
            );
        }

        $jitValue = $this->runtime->iniGet('opcache.jit');
        $jitEnabled = $this->isJitEnabled($jitValue);

        if (!$jitEnabled) {
            return match ($environment) {
                'production' => CheckResult::warning(
                    self::CHECK_NAME,
                    'JIT is not enabled (recommended for production performance)',
                    [
                        'Set opcache.jit=tracing in php.ini for best general-purpose performance.',
                        'Set opcache.jit_buffer_size=128M to allocate JIT buffer memory.',
                        'PHP ini settings differ between CLI and FPM SAPIs. Verify JIT configuration in the SAPI that handles production traffic.',
                    ],
                ),
                default => CheckResult::warning(
                    self::CHECK_NAME,
                    'JIT is not enabled (consider enabling to mirror production)',
                    ['Set opcache.jit=tracing and opcache.jit_buffer_size=128M.'],
                ),
            };
        }

        // JIT is enabled; check buffer size
        $bufferSize = $this->runtime->iniGet('opcache.jit_buffer_size');
        $bufferMb = $this->parseBufferMb($bufferSize);

        if ($environment === 'production' && $bufferMb < self::MIN_BUFFER_MB) {
            return CheckResult::warning(
                self::CHECK_NAME,
                sprintf(
                    'JIT buffer size is %dM (recommended >= %dM for production)',
                    $bufferMb,
                    self::MIN_BUFFER_MB,
                ),
                [
                    sprintf('Set opcache.jit_buffer_size=%dM or higher.', self::MIN_BUFFER_MB),
                    'PHP ini settings differ between CLI and FPM SAPIs. Verify JIT configuration in the SAPI that handles production traffic.',
                ],
            );
        }

        return CheckResult::pass(
            self::CHECK_NAME,
            sprintf('JIT is enabled (buffer: %dM)', $bufferMb),
        );
    }

    /**
     * Check whether OPcache is enabled for the current SAPI.
     */
    private function isOpcacheEnabled(): bool
    {
        // CLI uses opcache.enable_cli; non-CLI uses opcache.enable
        $key = PHP_SAPI === 'cli' ? 'opcache.enable_cli' : 'opcache.enable';

        return (bool) $this->runtime->iniGet($key);
    }

    /**
     * Determine whether a JIT config value means JIT is enabled.
     *
     * Handles all known forms:
     * - Disabled: false, '', '0', 'off', 'disable', 'disabled'
     * - Enabled: 'tracing', 'function', 'on', '1', numeric modes like '1205'
     */
    private function isJitEnabled(string|false $value): bool
    {
        if ($value === false) {
            return false;
        }

        $normalized = strtolower($value);

        if (in_array($normalized, self::DISABLED_VALUES, true)) {
            return false;
        }

        // Numeric mode: 4-digit like 1205, 1235, 1255: enabled if last digit > 0
        if (is_numeric($normalized) && $normalized !== '0') {
            return true;
        }

        // Named modes: tracing, function, on, 1
        return in_array($normalized, ['tracing', 'function', 'on', '1'], true)
            || is_numeric($normalized);
    }

    /**
     * Parse buffer size string to megabytes.
     *
     * Handles: '128M', '128m', '134217728' (bytes), false.
     */
    private function parseBufferMb(string|false $value): int
    {
        if ($value === false || $value === '' || $value === '0') {
            return 0;
        }

        $normalized = strtolower($value);

        if (str_ends_with($normalized, 'm')) {
            return (int) $normalized;
        }

        if (str_ends_with($normalized, 'g')) {
            return (int) $normalized * 1024;
        }

        if (str_ends_with($normalized, 'k')) {
            return intdiv((int) $normalized, 1024);
        }

        // Assume bytes
        return intdiv((int) $value, 1024 * 1024);
    }
}
