<?php

declare(strict_types=1);

namespace Pulsar\Config;

use function in_array;
use function is_array;
use function is_int;
use function is_string;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Typed configuration DTO for `config/deploy.php`.
 */
#[Api]
readonly class DeployConfig
{
    /** Default check configuration used when no explicit config is provided. */
    private const array DEFAULT_CHECKS = [
        'debug-mode' => ['enabled' => true, 'severity' => 'fail'],
        'opcache' => ['enabled' => true, 'severity' => 'fail'],
        'jit' => ['enabled' => true, 'severity' => 'warn'],
        'cache-settings' => ['enabled' => true, 'severity' => 'fail'],
        'filesystem-scan' => ['enabled' => true, 'severity' => 'fail'],
        'security-headers' => ['enabled' => true, 'severity' => 'fail'],
        'https-readiness' => ['enabled' => true, 'severity' => 'warn'],
        'http3-readiness' => ['enabled' => true, 'severity' => 'warn'],
        'health-endpoint' => ['enabled' => true, 'severity' => 'warn'],
        'rate-limiting' => ['enabled' => true, 'severity' => 'warn'],
        'request-size-limits' => ['enabled' => true, 'severity' => 'warn'],
        'trusted-proxies' => ['enabled' => true, 'severity' => 'warn'],
        'integrity' => ['enabled' => true, 'severity' => 'fail'],
    ];

    /**
     * @param list<string> $trustedProxies IP addresses or CIDR ranges of trusted proxies
     * @param array<string, array{enabled: bool, severity: string}> $checks Per-check configuration
     */
    public function __construct(
        public array $trustedProxies = [],
        public int $maxPostSizeMb = 8,
        public int $maxUploadSizeMb = 10,
        public bool $http3Enabled = false,
        public int $http3AltSvcMaxAge = 86400,
        public array $checks = self::DEFAULT_CHECKS,
    ) {}

    /**
     * Get the configuration for a specific check.
     *
     * @return array{enabled: bool, severity: string}
     */
    public function checkConfig(string $name): array
    {
        /** @var array{enabled: bool, severity: string} */
        return $this->checks[$name] ?? ['enabled' => true, 'severity' => 'warn'];
    }

    /**
     * Whether a specific check is enabled.
     */
    public function isCheckEnabled(string $name): bool
    {
        $config = $this->checks[$name] ?? ['enabled' => true];

        /** @var bool */
        return $config['enabled'] ?? true;
    }

    /**
     * @param array<string, mixed> $data Raw array from config/deploy.php
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        /** @var list<string> $trustedProxies */
        $trustedProxies = $data['trusted_proxies'] ?? [];

        /** @var array<string, mixed> $requestLimits */
        $requestLimits = $data['request_limits'] ?? [];

        /** @var array<string, mixed> $http3 */
        $http3 = $data['http3'] ?? [];

        $rawMaxPost = $requestLimits['max_post_size_mb'] ?? 8;
        $rawMaxUpload = $requestLimits['max_upload_size_mb'] ?? 10;
        $rawAltSvcMaxAge = $http3['alt_svc_max_age'] ?? 86400;

        /** @var array<string, mixed> $rawChecks */
        $rawChecks = $data['checks'] ?? [];
        $checks = self::parseChecks($rawChecks, $environment);

        return new self(
            trustedProxies: $trustedProxies,
            maxPostSizeMb: is_int($rawMaxPost) ? $rawMaxPost : 8,
            maxUploadSizeMb: is_int($rawMaxUpload) ? $rawMaxUpload : 10,
            http3Enabled: (bool) ($http3['enabled'] ?? false),
            http3AltSvcMaxAge: is_int($rawAltSvcMaxAge) ? $rawAltSvcMaxAge : 86400,
            checks: $checks,
        );
    }

    /**
     * Parse per-check configuration with env var overrides.
     *
     * Env override pattern: DEPLOY_CHECK_{NAME}_SEVERITY=fail|warn|off
     * (name is uppercased with hyphens replaced by underscores).
     *
     * @param array<string, mixed> $rawChecks
     * @return array<string, array{enabled: bool, severity: string}>
     */
    private static function parseChecks(array $rawChecks, Environment $environment): array
    {
        $defaults = self::DEFAULT_CHECKS;
        $result = [];

        foreach ($defaults as $name => $defaultConfig) {
            $config = $defaultConfig;

            if (isset($rawChecks[$name]) && is_array($rawChecks[$name])) {
                $rawSeverity = $rawChecks[$name]['severity'] ?? null;
                $config = [
                    'enabled' => (bool) ($rawChecks[$name]['enabled'] ?? $defaultConfig['enabled']),
                    'severity' => is_string($rawSeverity) ? $rawSeverity : $defaultConfig['severity'],
                ];
            }

            // Env var override: DEPLOY_CHECK_DEBUG_MODE_SEVERITY=fail|warn|off
            $envKey = 'DEPLOY_CHECK_' . strtoupper(str_replace('-', '_', $name)) . '_SEVERITY';
            $envValue = $environment->get($envKey);

            if (in_array($envValue, ['fail', 'warn', 'off'], true)) {
                $config['severity'] = $envValue;

                if ($envValue === 'off') {
                    $config['enabled'] = false;
                }
            }

            /** @var array{enabled: bool, severity: string} $config */
            $result[$name] = $config;
        }

        return $result;
    }
}
