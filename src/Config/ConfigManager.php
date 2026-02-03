<?php

declare(strict_types=1);

namespace Pulsar\Config;

use function is_array;
use function is_file;

use Pulsar\Config\Exception\ConfigException;

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
final class ConfigManager
{
    private ?Environment $environment = null;
    private ?ConfigRepository $repository = null;

    public function __construct(
        private readonly ?string $configPath = null,
        private readonly ?string $envFilePath = null,
        private readonly ?ConfigOverrides $overrides = null,
    ) {}

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
    }

    /**
     * Get the Environment instance.
     *
     * @throws ConfigException If load() has not been called.
     */
    public function environment(): Environment
    {
        if ($this->environment === null) {
            throw ConfigException::missingRequired('environment', 'ConfigManager (call load() first)');
        }

        return $this->environment;
    }

    /**
     * Get the ConfigRepository instance.
     *
     * @throws ConfigException If load() has not been called.
     */
    public function repository(): ConfigRepository
    {
        if ($this->repository === null) {
            throw ConfigException::missingRequired('repository', 'ConfigManager (call load() first)');
        }

        return $this->repository;
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
