<?php

declare(strict_types=1);

namespace Pulsar\Extensibility\Internal;

use NoDiscard;
use Override;
use Pulsar\Extensibility\CapabilityPolicy;
use Pulsar\Extensibility\EnvironmentInterface;
use Pulsar\Extensibility\ExtensionCapability;
use Pulsar\Extensibility\TrustTier;

use function getenv;
use function in_array;
use function str_starts_with;
use function strtoupper;

/**
 * Capability-gated proxy for environment variable access.
 *
 * Enforces trust tier restrictions on environment variable access:
 * - Sensitive variables (master keys, database credentials) require Core or Verified tier
 * - General environment access requires the EnvRead capability
 * - Deny-by-default for untrusted extensions
 *
 * @internal Not part of the public API
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
final readonly class ScopedEnvironmentProxy implements EnvironmentInterface
{
    /** Environment variable patterns denied to non-Core tiers. */
    private const array SENSITIVE_PREFIXES = [
        'PULSAR_MASTER_KEY',
        'DB_PASSWORD',
        'DB_USERNAME',
        'DB_HOST',
        'DB_PORT',
        'DB_DATABASE',
        'DATABASE_URL',
        'REDIS_PASSWORD',
        'REDIS_URL',
        'APP_KEY',
        'APP_SECRET',
        'JWT_SECRET',
        'ENCRYPTION_KEY',
        'AWS_SECRET',
        'SMTP_PASSWORD',
    ];

    /**
     * @param list<ExtensionCapability> $additionalCapabilities Per-extension extra grants
     */
    public function __construct(
        private TrustTier $tier,
        private CapabilityPolicy $policy,
        private array $additionalCapabilities = [],
    ) {}

    #[Override]
    #[NoDiscard]
    public function get(string $key): ?string
    {
        if (!$this->isAccessible($key)) {
            return null;
        }

        $value = getenv($key);

        return $value !== false ? $value : null;
    }

    #[Override]
    public function has(string $key): bool
    {
        if (!$this->isAccessible($key)) {
            return false;
        }

        return getenv($key) !== false;
    }

    private function isAccessible(string $key): bool
    {
        // Must have EnvRead capability
        if (!$this->hasCapability(ExtensionCapability::EnvRead)) {
            return false;
        }

        // Core tier has unrestricted access
        if ($this->tier === TrustTier::Core) {
            return true;
        }

        // Sensitive variables require at least Verified tier with CryptoKeyAccess
        if ($this->isSensitive($key)) {
            return $this->tier->atLeast(TrustTier::Verified)
                && $this->hasCapability(ExtensionCapability::CryptoKeyAccess);
        }

        return true;
    }

    private function isSensitive(string $key): bool
    {
        $upper = strtoupper($key);

        return array_any(self::SENSITIVE_PREFIXES, static fn(string $prefix): bool => $upper === $prefix || str_starts_with($upper, $prefix . '_'));
    }

    private function hasCapability(ExtensionCapability $capability): bool
    {
        if ($this->policy->allows($this->tier, $capability)) {
            return true;
        }

        return in_array($capability, $this->additionalCapabilities, true);
    }
}
