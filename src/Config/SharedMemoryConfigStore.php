<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Config\Exception\ConfigException;
use RuntimeException;

use function extension_loaded;
use function hash_equals;
use function hash_hmac;
use function md5;
use function shmop_close;
use function shmop_delete;
use function shmop_open;
use function shmop_read;
use function shmop_write;
use function strlen;
use function substr;
use function unpack;

/**
 * Shared-memory config store for persistent workers.
 *
 * Loads compiled configuration from a POSIX shared memory segment (shmop)
 * instead of reading files on every request. The first worker writes the
 * config to shared memory; subsequent workers and requests read from it
 * with zero file I/O.
 *
 * Data integrity is verified with HMAC-SHA256 before any deserialization.
 * Only data written by this store with the correct HMAC key will be accepted.
 *
 * Requires ext-shmop.
 * @api
 */
#[Api(since: '1.0.0')]
final class SharedMemoryConfigStore
{
    /**
     * 4-byte length prefix + 32-byte HMAC + serialized data.
     */
    private const int HEADER_SIZE = 4;

    private const int HMAC_SIZE = 32;

    /**
     * Maximum shared memory segment size (16 MB).
     */
    private const int MAX_SIZE = 16 * 1024 * 1024;

    /**
     * Explicit allowlist of every class/enum reachable in a serialized
     * ConfigRepository graph. Deliberately tight — NOT the broad
     * {@see \Pulsar\Cache\CacheAllowedClasses} scan — so that even if the HMAC
     * key is compromised, unserialize() can only ever instantiate this exact set
     * of config value objects (defense in depth). Completeness is enforced by a
     * reflection guard in the test suite: any config DTO that gains a new
     * class/enum-typed property must be added here or the build fails, so this
     * list can never silently drift out of sync with the config graph.
     *
     * @var list<class-string>
     */
    private const array DESERIALIZATION_ALLOWLIST = [
        \Pulsar\Config\ConfigRepository::class,
        \Pulsar\Config\AppConfig::class,
        \Pulsar\Config\AppSignature::class,
        \Pulsar\Config\EnvironmentMode::class,
        \Pulsar\Config\DatabaseConfig::class,
        \Pulsar\Config\ConnectionConfig::class,
        \Pulsar\Database\Driver::class,
        \Pulsar\Config\SecurityConfig::class,
        \Pulsar\Config\AuthConfig::class,
        \Pulsar\Config\TwoFactorConfig::class,
        \Pulsar\Config\AuthorizationConfig::class,
        \Pulsar\Config\SessionConfig::class,
        \Pulsar\Config\CsrfConfig::class,
        \Pulsar\Config\SecurityHeadersConfig::class,
        \Pulsar\Config\CspConfig::class,
        \Pulsar\Config\HstsConfig::class,
        \Pulsar\Config\CrossOriginConfig::class,
        \Pulsar\Config\PermissionsPolicyConfig::class,
        \Pulsar\Config\NelConfig::class,
        \Pulsar\Config\RateLimitConfig::class,
        \Pulsar\Config\ObservabilityConfig::class,
        \Pulsar\Config\LoggingChannelConfig::class,
        \Pulsar\Config\TracingConfig::class,
        \Pulsar\Config\MetricsConfig::class,
        \Pulsar\Config\ErrorTrackingConfig::class,
        \Pulsar\Config\AuditConfig::class,
        \Pulsar\Config\ComplianceLoggingConfig::class,
        \Pulsar\Config\IntegrityConfig::class,
        \Pulsar\Config\IntegrityPolicyMode::class,
        \Pulsar\Config\Environment::class,
        \Pulsar\Database\Pool\PoolConfig::class,
        \Pulsar\Database\Routing\ReadWriteConfig::class,
        \Pulsar\Database\Failover\FailoverConfig::class,
        \Pulsar\Database\Cache\QueryCacheConfig::class,
        \Pulsar\Database\Monitor\MonitorConfig::class,
        \Pulsar\Config\ResilienceConfig::class,
        \Pulsar\Config\RetryConfig::class,
        \Pulsar\Config\CircuitBreakerConfig::class,
        \Pulsar\Config\HealthCheckConfig::class,
        \Pulsar\Config\ApiConfig::class,
        \Pulsar\Api\Resource\ComplexityLimits::class,
        \Pulsar\Config\AuthGuardConfig::class,
        \Pulsar\Config\BusinessProfileConfig::class,
        \Pulsar\Config\CacheConfig::class,
        \Pulsar\Config\CacheDriverType::class,
        \Pulsar\Config\CachePoolConfig::class,
        \Pulsar\Config\DeployConfig::class,
        \Pulsar\Config\DiskConfig::class,
        \Pulsar\Config\EventConfig::class,
        \Pulsar\Config\FeatureFlagConfig::class,
        \Pulsar\FeatureFlag\FlagStorageDriver::class,
        \Pulsar\Config\I18nConfig::class,
        \Pulsar\I18n\Locale\LocaleUrlStrategy::class,
        \Pulsar\Config\MailConfig::class,
        \Pulsar\Config\MailDriverType::class,
        \Pulsar\Config\MailEncryptionPolicy::class,
        \Pulsar\Mail\Webhook\MailWebhookConfig::class,
        \Pulsar\Config\NotificationConfig::class,
        \Pulsar\Config\QueueConfig::class,
        \Pulsar\Config\QueueDriverType::class,
        \Pulsar\Config\RuntimeConfig::class,
        \Pulsar\Config\SchedulerConfig::class,
        \Pulsar\Config\StorageConfig::class,
        \Pulsar\Config\StorageDriver::class,
        \Pulsar\Config\StormProtectionConfig::class,
        \Pulsar\Config\SupervisorConfig::class,
        \Pulsar\Config\TenancyConfig::class,
        \Pulsar\Config\TenantDatabaseConfig::class,
        \Pulsar\Tenancy\TenantDatabaseStrategy::class,
        \Pulsar\Tenancy\TenantResolverStrategy::class,
        \Pulsar\View\ViewConfig::class,
    ];

    private readonly int $shmKey;

    /**
     * @param string $hmacKey Secret key for HMAC integrity verification.
     *                        Derive from PULSAR_MASTER_KEY or app key: never hardcode.
     * @param string $projectId Unique identifier for the config segment
     * @param int $maxSize Maximum segment size in bytes
     */
    public function __construct(
        private readonly string $hmacKey,
        string $projectId = 'pulsar_config',
        private readonly int $maxSize = self::MAX_SIZE,
    ) {
        if (!extension_loaded('shmop')) {
            throw new RuntimeException('ext-shmop is required for SharedMemoryConfigStore');
        }

        if ($this->hmacKey === '') {
            throw new RuntimeException('HMAC key must not be empty');
        }

        if (strlen($this->hmacKey) < 32) {
            throw new RuntimeException('HMAC key must be at least 32 bytes (256 bits) for HMAC-SHA256');
        }

        $this->shmKey = $this->generateKey($projectId);
    }

    /**
     * Write a ConfigRepository to shared memory.
     *
     * Serializes all config DTOs and writes them with HMAC integrity protection.
     * If a segment already exists, it is replaced.
     */
    public function write(ConfigRepository $repository): void
    {
        $serialized = serialize($repository);
        $hmac = hash_hmac('sha256', $serialized, $this->hmacKey, binary: true);
        $dataLength = strlen($serialized);
        $totalSize = self::HEADER_SIZE + self::HMAC_SIZE + $dataLength;

        if ($totalSize > $this->maxSize) {
            throw new ConfigException(
                "Config data exceeds maximum shared memory size ({$totalSize} > {$this->maxSize})",
            );
        }

        // Delete existing segment if present
        $this->delete();

        $shm = @shmop_open($this->shmKey, 'c', 0o600, $totalSize);

        if ($shm === false) {
            throw new ConfigException('Failed to create shared memory segment');
        }

        try {
            // Layout: [4-byte length][32-byte HMAC][serialized data]
            $header = pack('N', $dataLength);
            $payload = $header . $hmac . $serialized;
            $written = shmop_write($shm, $payload, 0);

            if ($written !== strlen($payload)) {
                throw new ConfigException('Failed to write full payload to shared memory segment');
            }
        } finally {
            /** @psalm-suppress UnusedFunctionCall */
            shmop_close($shm);
        }
    }

    /**
     * Read a ConfigRepository from shared memory.
     *
     * Verifies HMAC integrity before deserializing. Deserialization uses
     * allowed_classes restricted to ConfigRepository and known config DTOs
     * as defense-in-depth.
     *
     * @return ConfigRepository|null Null if no segment exists, HMAC fails, or data is invalid
     */
    #[NoDiscard]
    public function read(): ?ConfigRepository
    {
        $shm = @shmop_open($this->shmKey, 'a', 0, 0);

        if ($shm === false) {
            return null;
        }

        try {
            $header = shmop_read($shm, 0, self::HEADER_SIZE);

            if (strlen($header) < self::HEADER_SIZE) {
                return null;
            }

            /** @var array{1: int} $unpacked */
            $unpacked = unpack('N', $header);
            $dataLength = $unpacked[1];

            if ($dataLength <= 0 || $dataLength > $this->maxSize) {
                return null;
            }

            // Read HMAC
            $storedHmac = shmop_read($shm, self::HEADER_SIZE, self::HMAC_SIZE);

            if (strlen($storedHmac) < self::HMAC_SIZE) {
                return null;
            }

            // Read serialized data
            $serialized = shmop_read($shm, self::HEADER_SIZE + self::HMAC_SIZE, $dataLength);

            // Verify HMAC before deserializing; reject tampered data
            $expectedHmac = hash_hmac('sha256', $serialized, $this->hmacKey, binary: true);

            if (!hash_equals($expectedHmac, $storedHmac)) {
                return null;
            }

            // HMAC verified: data was written by us with the correct key.
            // Restrict deserialization to the explicit set of config DTO classes
            // that a ConfigRepository graph contains (see DESERIALIZATION_ALLOWLIST).
            // This prevents gadget-chain attacks even if the HMAC key is compromised
            // (defense in depth).
            /** @var mixed $result */
            $result = unserialize($serialized, ['allowed_classes' => self::DESERIALIZATION_ALLOWLIST]);

            return $result instanceof ConfigRepository ? $result : null;
        } finally {
            /** @psalm-suppress UnusedFunctionCall */
            shmop_close($shm);
        }
    }

    /**
     * Delete the shared memory segment.
     */
    public function delete(): void
    {
        $shm = @shmop_open($this->shmKey, 'w', 0, 0);

        if ($shm === false) {
            return;
        }

        try {
            /** @psalm-suppress UnusedFunctionCall */
            shmop_delete($shm);
        } finally {
            /** @psalm-suppress UnusedFunctionCall */
            shmop_close($shm);
        }
    }

    /**
     * Check whether a config segment exists in shared memory.
     */
    #[NoDiscard]
    public function exists(): bool
    {
        $shm = @shmop_open($this->shmKey, 'a', 0, 0);

        if ($shm === false) {
            return false;
        }

        /** @psalm-suppress UnusedFunctionCall */
        shmop_close($shm);

        return true;
    }

    /**
     * Generate a deterministic IPC key from a project identifier.
     */
    private function generateKey(string $projectId): int
    {
        // Use a hash-based approach for portability (ftok requires a real file)
        $hash = md5($projectId);

        // Take first 7 hex chars (28 bits) to stay within positive int range on 32-bit
        $key = (int) hexdec(substr($hash, 0, 7));

        // Ensure non-zero (shmop_open rejects key 0)
        return $key === 0 ? 1 : $key;
    }
}
