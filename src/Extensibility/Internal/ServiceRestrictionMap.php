<?php

declare(strict_types=1);

namespace Pulsar\Extensibility\Internal;

use NoDiscard;
use Pulsar\Extensibility\ExtensionCapability;

use function array_key_exists;
use function in_array;

/**
 * Maps service IDs to required capabilities for deny-by-default enforcement.
 *
 * Services fall into three categories:
 * - Restricted: require a specific capability to resolve
 * - Safe: any tier with ContainerRead can resolve
 * - Unknown: denied for non-Core tiers (deny-by-default)
 *
 * @internal Not part of the public API — used by ScopedContainerProxy
 */
readonly class ServiceRestrictionMap
{
    /**
     * @param array<string, ExtensionCapability> $restrictedServices Service ID => required capability
     * @param list<string> $safeServices Service IDs any tier can read
     */
    public function __construct(
        private array $restrictedServices,
        private array $safeServices,
    ) {}

    /**
     * Create the default restriction map with built-in classifications.
     */
    #[NoDiscard]
    public static function defaults(): self
    {
        return new self(
            restrictedServices: [
                // Crypto — key material access
                'Pulsar\Security\Crypto\MasterKey' => ExtensionCapability::CryptoKeyAccess,
                'Pulsar\Security\Crypto\KeyProviderInterface' => ExtensionCapability::CryptoKeyAccess,

                // Crypto — operations
                'Pulsar\Security\Crypto\EncryptorInterface' => ExtensionCapability::CryptoOperations,
                'Pulsar\Security\Crypto\HmacInterface' => ExtensionCapability::CryptoOperations,

                // Database
                'Pulsar\Database\ConnectionInterface' => ExtensionCapability::DatabaseRaw,
                'Pulsar\Database\DatabaseManagerInterface' => ExtensionCapability::DatabaseRaw,

                // Audit
                'Pulsar\Audit\AuditSinkInterface' => ExtensionCapability::AuditSinkAccess,

                // Filesystem
                'Pulsar\Storage\FilesystemInterface' => ExtensionCapability::FilesystemWrite,

                // Network
                'Pulsar\Http\Client\HttpClientInterface' => ExtensionCapability::NetworkEgress,

                // Environment
                'Pulsar\Config\Environment' => ExtensionCapability::EnvRead,

                // Config mutation
                'Pulsar\Config\ConfigRepositoryInterface' => ExtensionCapability::ConfigWrite,

                // Process execution
                'Pulsar\Process\ProcessManagerInterface' => ExtensionCapability::ProcessExec,
            ],
            safeServices: [
                'Psr\Log\LoggerInterface',
                'Psr\EventDispatcher\EventDispatcherInterface',
                'Psr\Clock\ClockInterface',
                'Pulsar\Config\AppConfig',
                'Pulsar\Config\SecurityConfig',
                'Pulsar\Config\SecurityHeadersConfig',
                'Pulsar\Config\ObservabilityConfig',
                'Pulsar\Config\CacheConfig',
                'Pulsar\Config\I18nConfig',
                'Pulsar\Observability\Metrics\MetricRegistryInterface',
                'Pulsar\Audit\AuditLoggerInterface',
                'Pulsar\Cache\FrameworkCacheInterface',
                'Pulsar\Extensibility\ExtensionRegistry',
                'Pulsar\Container\ContainerInterface',
                'Pulsar\Routing\RouterInterface',
            ],
        );
    }

    /**
     * Get the required capability for a service, or null if safe.
     */
    public function requiredCapability(string $serviceId): ?ExtensionCapability
    {
        return $this->restrictedServices[$serviceId] ?? null;
    }

    /**
     * Check if a service requires a specific capability.
     */
    public function isRestricted(string $serviceId): bool
    {
        return array_key_exists($serviceId, $this->restrictedServices);
    }

    /**
     * Check if a service is on the safe allowlist.
     */
    public function isSafe(string $serviceId): bool
    {
        return in_array($serviceId, $this->safeServices, true);
    }

    /**
     * Check if a service is not classified (not restricted and not safe).
     */
    public function isUnknown(string $serviceId): bool
    {
        return !$this->isRestricted($serviceId) && !$this->isSafe($serviceId);
    }
}
