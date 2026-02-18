<?php

declare(strict_types=1);

namespace Pulsar\Config;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Config\Exception\ConfigException;

use function is_array;
use function is_file;

/**
 * Configuration orchestrator.
 *
 * Manages the full config loading pipeline:
 * 1. OS env vars (always present)
 * 2. `.env` file (if provided) — file values never override existing OS vars
 * 3. Config PHP files (return raw arrays)
 * 4. Runtime overrides (applied via array_replace_recursive)
 * 5. Typed DTO construction (env vars override array values inside factories)
 */
#[Internal]
final class ConfigManager implements ConfigManagerInterface
{
    private ?Environment $environment = null;
    private ?ConfigRepository $repository = null;

    public function __construct(
        private readonly ?string $configPath = null,
        private readonly ?string $envFilePath = null,
        private readonly ?ConfigOverrides $overrides = null,
    ) {}

    /**
     * Load configuration from a cached ConfigRepository.
     *
     * Restores Environment and sets the pre-built repository directly,
     * skipping file reads and DTO construction. The cached repository
     * must contain all required config DTOs.
     *
     * @return bool True if the cache was successfully loaded.
     */
    public function loadFromCache(ConfigRepository $cached): bool
    {
        if (!$cached->has(AppConfig::class)) {
            return false;
        }

        $this->environment = Environment::load($this->envFilePath);
        $this->repository = $cached;

        return true;
    }

    /**
     * Load all configuration.
     *
     * Creates Environment, reads config files, applies overrides,
     * and builds typed DTOs into the ConfigRepository.
     */
    public function load(): void
    {
        $this->environment = Environment::load($this->envFilePath);
        $this->repository = new ConfigRepository();

        // Load app config
        $appData = $this->loadConfigFile('app');
        $appConfig = AppConfig::fromArray($appData, $this->environment);
        $this->repository->set($appConfig);

        // Load observability config
        $observabilityData = $this->loadConfigFile('observability');
        $observabilityConfig = ObservabilityConfig::fromArray($observabilityData, $this->environment);
        $this->repository->set($observabilityConfig);

        // Load security config
        $securityData = $this->loadConfigFile('security');
        $securityConfig = SecurityConfig::fromArray($securityData, $this->environment);
        $this->repository->set($securityConfig);

        // Load database config (optional — only if config/database.php exists)
        if ($this->configPath !== null && is_file($this->configPath . DIRECTORY_SEPARATOR . 'database.php')) {
            $databaseData = $this->loadConfigFile('database');
            $databaseConfig = DatabaseConfig::fromArray($databaseData, $this->environment);
            $this->repository->set($databaseConfig);
        }

        // Load tenancy config (optional — only if config/tenancy.php exists)
        if ($this->configPath !== null && is_file($this->configPath . DIRECTORY_SEPARATOR . 'tenancy.php')) {
            $tenancyData = $this->loadConfigFile('tenancy');
            $tenancyConfig = TenancyConfig::fromArray($tenancyData, $this->environment);
            $this->repository->set($tenancyConfig);
        }

        // Load feature flags config (optional — only if config/features.php exists)
        if ($this->configPath !== null && is_file($this->configPath . DIRECTORY_SEPARATOR . 'features.php')) {
            $featuresData = $this->loadConfigFile('features');
            $featureFlagConfig = FeatureFlagConfig::fromArray($featuresData, $this->environment);
            $this->repository->set($featureFlagConfig);
        }

        // Load scheduler config (optional — only if config/scheduler.php exists)
        if ($this->configPath !== null && is_file($this->configPath . DIRECTORY_SEPARATOR . 'scheduler.php')) {
            $schedulerData = $this->loadConfigFile('scheduler');
            $schedulerConfig = SchedulerConfig::fromArray($schedulerData, $this->environment);
            $this->repository->set($schedulerConfig);
        }

        // Load resilience config (optional — only if config/resilience.php exists)
        if ($this->configPath !== null && is_file($this->configPath . DIRECTORY_SEPARATOR . 'resilience.php')) {
            $resilienceData = $this->loadConfigFile('resilience');
            $resilienceConfig = ResilienceConfig::fromArray($resilienceData, $this->environment);
            $this->repository->set($resilienceConfig);
        }

        // Load queue config (optional — only if config/queue.php exists)
        if ($this->configPath !== null && is_file($this->configPath . DIRECTORY_SEPARATOR . 'queue.php')) {
            $queueData = $this->loadConfigFile('queue');
            $queueConfig = QueueConfig::fromArray($queueData, $this->environment);
            $this->repository->set($queueConfig);
        }

        // Load storage config (optional — only if config/storage.php exists)
        if ($this->configPath !== null && is_file($this->configPath . DIRECTORY_SEPARATOR . 'storage.php')) {
            $storageData = $this->loadConfigFile('storage');
            $storageConfig = StorageConfig::fromArray($storageData, $this->environment);
            $this->repository->set($storageConfig);
        }

        // Load supervisor config (optional — only if config/supervisor.php exists)
        if ($this->configPath !== null && is_file($this->configPath . DIRECTORY_SEPARATOR . 'supervisor.php')) {
            $supervisorData = $this->loadConfigFile('supervisor');
            $supervisorConfig = SupervisorConfig::fromArray($supervisorData, $this->environment);
            $this->repository->set($supervisorConfig);
        }

        // Load integrity config (optional — only if config/integrity.php exists)
        if ($this->configPath !== null && is_file($this->configPath . DIRECTORY_SEPARATOR . 'integrity.php')) {
            $integrityData = $this->loadConfigFile('integrity');
            $integrityConfig = IntegrityConfig::fromArray($integrityData, $this->environment);
            $this->repository->set($integrityConfig);
        }

        // Load deploy config (optional — only if config/deploy.php exists)
        if ($this->configPath !== null && is_file($this->configPath . DIRECTORY_SEPARATOR . 'deploy.php')) {
            $deployData = $this->loadConfigFile('deploy');
            $deployConfig = DeployConfig::fromArray($deployData, $this->environment);
            $this->repository->set($deployConfig);
        }

        // Load runtime config (optional — only if config/runtime.php exists)
        if ($this->configPath !== null && is_file($this->configPath . DIRECTORY_SEPARATOR . 'runtime.php')) {
            $runtimeData = $this->loadConfigFile('runtime');
            $runtimeConfig = RuntimeConfig::fromArray($runtimeData, $this->environment);
            $this->repository->set($runtimeConfig);
        }

        // Load cache config (optional — only if config/cache.php exists)
        if ($this->configPath !== null && is_file($this->configPath . DIRECTORY_SEPARATOR . 'cache.php')) {
            $cacheData = $this->loadConfigFile('cache');
            $cacheConfig = CacheConfig::fromArray($cacheData, $this->environment);
            $this->repository->set($cacheConfig);
        }

        // Load mail config (optional — only if config/mail.php exists)
        if ($this->configPath !== null && is_file($this->configPath . DIRECTORY_SEPARATOR . 'mail.php')) {
            $mailData = $this->loadConfigFile('mail');
            $mailConfig = MailConfig::fromArray($mailData, $this->environment);
            $this->repository->set($mailConfig);
        }

        // Load notification config (optional — only if config/notification.php exists)
        if ($this->configPath !== null && is_file($this->configPath . DIRECTORY_SEPARATOR . 'notification.php')) {
            $notificationData = $this->loadConfigFile('notification');
            $notificationConfig = NotificationConfig::fromArray($notificationData, $this->environment);
            $this->repository->set($notificationConfig);
        }

        // Load API config (optional — only if config/api.php exists)
        if ($this->configPath !== null && is_file($this->configPath . DIRECTORY_SEPARATOR . 'api.php')) {
            $apiData = $this->loadConfigFile('api');
            $apiConfig = ApiConfig::fromArray($apiData);
            $this->repository->set($apiConfig);
        }

        // Studio config is NOT loaded here — it is loaded directly by Kernel::studioPreboot()
        // to avoid introducing a StudioConfig dependency in ConfigManager.
    }

    /**
     * Get the config directory path.
     */
    #[Override]
    public function configPath(): ?string
    {
        return $this->configPath;
    }

    /**
     * Get the Environment instance.
     *
     * @throws ConfigException If load() has not been called.
     */
    #[Override]
    public function environment(): Environment
    {
        return $this->environment ?? throw ConfigException::missingRequired('environment', 'ConfigManager (call load() first)');
    }

    /**
     * Get the ConfigRepository instance.
     *
     * @throws ConfigException If load() has not been called.
     */
    #[Override]
    public function repository(): ConfigRepository
    {
        return $this->repository ?? throw ConfigException::missingRequired('repository', 'ConfigManager (call load() first)');
    }

    /**
     * Load a config PHP file, apply overrides, and return the raw array.
     *
     * @return array<string, mixed>
     *
     * @throws ConfigException If the file doesn't exist or doesn't return an array.
     */
    private function loadConfigFile(string $name): array
    {
        if ($this->configPath === null) {
            return $this->applyOverrides($name, []);
        }

        $path = $this->configPath . DIRECTORY_SEPARATOR . $name . '.php';

        if (!is_file($path)) {
            throw ConfigException::fileNotFound($path);
        }

        $data = require $path;

        if (!is_array($data)) {
            throw ConfigException::invalidValue($path, 'file must return an array');
        }

        /** @var array<string, mixed> $data */
        return $this->applyOverrides($name, $data);
    }

    /**
     * Apply runtime overrides if present.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function applyOverrides(string $domain, array $data): array
    {
        if ($this->overrides === null) {
            return $data;
        }

        return $this->overrides->apply($domain, $data);
    }
}
