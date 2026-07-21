<?php

declare(strict_types=1);

namespace Pulsar\Config;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Cloud\CloudConfig;
use Pulsar\Config\Exception\ConfigException;
use Pulsar\Config\Exception\MissingConfigException;
use Pulsar\View\ViewConfig;

use function array_filter;
use function array_key_exists;
use function array_values;
use function dirname;
use function is_array;
use function is_file;
use function sprintf;

/**
 * Configuration orchestrator.
 *
 * Manages the full config loading pipeline:
 * 1. OS env vars (always present)
 * 2. `.env` file (if provided): file values never override existing OS vars
 * 3. Config PHP files (return raw arrays)
 * 4. Runtime overrides (applied via array_replace_recursive)
 * 5. Typed DTO construction (env vars override array values inside factories)
 */
#[Internal]
final class ConfigManager implements ConfigManagerInterface
{
    /**
     * Configuration files that must exist when a config path is set.
     *
     * These are loaded unconditionally by {@see load()} and have no
     * optional-existence check, so missing files would cause a confusing
     * {@see ConfigException}. Validating upfront gives a clear message.
     *
     * @var list<string>
     */
    public const array REQUIRED_CONFIGS = ['app', 'security', 'observability'];

    private ?Environment $environment = null;
    private ?ConfigRepository $repository = null;

    /**
     * Whether an unrecognized config key fails the boot (strict) or is only
     * warned about. Resolved from PULSAR_CONFIG_STRICT / config.strict_keys,
     * defaulting to warn (see {@see resolveStrictKeys()}). Set during load().
     */
    private bool $strictKeys = false;

    /**
     * Human-readable descriptions of unrecognized config keys found during the
     * last load(), for a boot-time reporter to log when not in strict mode.
     *
     * @var list<string>
     */
    private array $unknownKeyWarnings = [];

    /**
     * F4.10: extension-registered config loaders. Each entry is
     * `(basename, optional, loader)`. After the framework's
     * hardcoded sections are loaded, `load()` walks this list
     * and resolves each registered loader against
     * `<configPath>/<basename>.php` — present-file gets parsed
     * and passed to the loader, missing-file throws when
     * `optional === false`. Extensions register via
     * `registerLoader()` during their wiring step.
     *
     * @var list<array{basename: string, optional: bool, loader: ConfigLoaderInterface}>
     */
    private array $extensionLoaders = [];

    public function __construct(
        private readonly ?string $configPath = null,
        private readonly ?string $envFilePath = null,
        private readonly ?ConfigOverrides $overrides = null,
    ) {}

    /**
     * F4.10: register a typed-config loader for an extension's
     * own `config/<basename>.php` file. The loader's
     * `configClass()` declares which DTO it produces, and its
     * `load()` builds that DTO from the parsed array. The
     * resulting object is stored in the repository keyed by
     * the DTO's class-string — extensions can resolve it via
     * `repository()->get(MyExtensionConfig::class)`.
     *
     * Loaders are invoked AFTER the framework's hardcoded
     * sections, so an extension loader can depend on
     * `AppConfig`, `SecurityConfig`, etc. being already
     * resolved in the repository. The order of registration
     * defines the order of activation within the extension
     * tier — wire dependencies accordingly.
     */
    public function registerLoader(string $basename, ConfigLoaderInterface $loader, bool $optional = true): self
    {
        $this->extensionLoaders[] = [
            'basename' => $basename,
            'optional' => $optional,
            'loader' => $loader,
        ];
        return $this;
    }

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
        // Bind the active environment so env() resolves .env even on the cached
        // config path (ADR-0033).
        Environment::activate($this->environment);
        $this->repository = $cached;

        return true;
    }

    /**
     * Validate that all required configuration files exist.
     *
     * Called automatically at the start of {@see load()}. Can also be
     * called independently to check config completeness before boot.
     *
     * @param list<string>|null $required Override the default required list (for testing)
     *
     * @throws MissingConfigException If any required config files are missing
     */
    public function validateRequiredConfigs(?array $required = null): void
    {
        if ($this->configPath === null) {
            return;
        }

        $requiredNames = $required ?? self::REQUIRED_CONFIGS;
        $missing = array_values(array_filter(
            $requiredNames,
            fn(string $name): bool => !is_file(
                $this->configPath . DIRECTORY_SEPARATOR . $name . '.php',
            ),
        ));

        if ($missing !== []) {
            throw MissingConfigException::forFiles($missing);
        }
    }

    /**
     * Load all configuration.
     *
     * Creates Environment, reads config files, applies overrides,
     * and builds typed DTOs into the ConfigRepository.
     *
     * @throws MissingConfigException If required config files are missing
     */
    public function load(): void
    {
        $this->validateRequiredConfigs();

        $this->environment = Environment::load($this->envFilePath);
        // Bind the active environment before any config/*.php is required, so
        // env() calls inside config files resolve .env values (ADR-0033).
        Environment::activate($this->environment);
        $this->repository = new ConfigRepository();

        // Load app config
        $appData = $this->loadConfigFile('app');
        $appConfig = AppConfig::fromArray($appData, $this->environment);
        $this->repository->set($appConfig);

        // Resolve strict-key checking now that app config + environment exist.
        $this->strictKeys = $this->resolveStrictKeys($appData);

        // Load observability config
        $observabilityData = $this->loadConfigFile('observability');
        $observabilityConfig = ObservabilityConfig::fromArray($observabilityData, $this->environment);
        $this->repository->set($observabilityConfig);

        // Load security config
        $securityData = $this->loadConfigFile('security');
        $securityConfig = SecurityConfig::fromArray($securityData, $this->environment);
        $this->repository->set($securityConfig);

        // Load i18n config (optional; only if config/i18n.php exists)
        if ($this->configPath !== null && is_file($this->configPath . DIRECTORY_SEPARATOR . 'i18n.php')) {
            $i18nData = $this->loadConfigFile('i18n');
            $i18nConfig = I18nConfig::fromArray($i18nData, $this->environment);
            $this->repository->set($i18nConfig);
        }

        // Load event config (optional; only if config/event.php exists)
        if ($this->configPath !== null && is_file($this->configPath . DIRECTORY_SEPARATOR . 'event.php')) {
            $eventData = $this->loadConfigFile('event');
            $eventConfig = EventConfig::fromArray($eventData, $this->environment);
            $this->repository->set($eventConfig);
        }

        // Load database config (optional; only if config/database.php exists)
        if ($this->configPath !== null && is_file($this->configPath . DIRECTORY_SEPARATOR . 'database.php')) {
            $databaseData = $this->loadConfigFile('database');
            // Pass project root (parent of config/) so SQLite relative paths resolve correctly
            $projectRoot = dirname($this->configPath);
            $databaseConfig = DatabaseConfig::fromArray($databaseData, $this->environment, $projectRoot);
            $this->repository->set($databaseConfig);
        }

        // Load tenancy config (optional; only if config/tenancy.php exists)
        if ($this->configPath !== null && is_file($this->configPath . DIRECTORY_SEPARATOR . 'tenancy.php')) {
            $tenancyData = $this->loadConfigFile('tenancy');
            $tenancyConfig = TenancyConfig::fromArray($tenancyData, $this->environment);
            $this->repository->set($tenancyConfig);
        }

        // Load feature flags config (optional; only if config/features.php exists)
        if ($this->configPath !== null && is_file($this->configPath . DIRECTORY_SEPARATOR . 'features.php')) {
            $featuresData = $this->loadConfigFile('features');
            $featureFlagConfig = FeatureFlagConfig::fromArray($featuresData, $this->environment);
            $this->repository->set($featureFlagConfig);
        }

        // Load scheduler config (optional; only if config/scheduler.php exists)
        if ($this->configPath !== null && is_file($this->configPath . DIRECTORY_SEPARATOR . 'scheduler.php')) {
            $schedulerData = $this->loadConfigFile('scheduler');
            $schedulerConfig = SchedulerConfig::fromArray($schedulerData, $this->environment);
            $this->repository->set($schedulerConfig);
        }

        // Load resilience config (optional; only if config/resilience.php exists)
        if ($this->configPath !== null && is_file($this->configPath . DIRECTORY_SEPARATOR . 'resilience.php')) {
            $resilienceData = $this->loadConfigFile('resilience');
            $resilienceConfig = ResilienceConfig::fromArray($resilienceData, $this->environment);
            $this->repository->set($resilienceConfig);
        }

        // Load queue config (optional; only if config/queue.php exists)
        if ($this->configPath !== null && is_file($this->configPath . DIRECTORY_SEPARATOR . 'queue.php')) {
            $queueData = $this->loadConfigFile('queue');
            $queueConfig = QueueConfig::fromArray($queueData, $this->environment);
            $this->repository->set($queueConfig);
        }

        // Load routing config (optional; only if config/routing.php exists)
        if ($this->configPath !== null && is_file($this->configPath . DIRECTORY_SEPARATOR . 'routing.php')) {
            $routingData = $this->loadConfigFile('routing');
            $routingConfig = RoutingConfig::fromArray($routingData, $this->environment);
            $this->repository->set($routingConfig);
        }

        // Load storage config (optional; only if config/storage.php exists)
        if ($this->configPath !== null && is_file($this->configPath . DIRECTORY_SEPARATOR . 'storage.php')) {
            $storageData = $this->loadConfigFile('storage');
            $storageConfig = StorageConfig::fromArray($storageData, $this->environment);
            $this->repository->set($storageConfig);
        }

        // Load supervisor config (optional; only if config/supervisor.php exists)
        if ($this->configPath !== null && is_file($this->configPath . DIRECTORY_SEPARATOR . 'supervisor.php')) {
            $supervisorData = $this->loadConfigFile('supervisor');
            $supervisorConfig = SupervisorConfig::fromArray($supervisorData, $this->environment);
            $this->repository->set($supervisorConfig);
        }

        // Load integrity config (optional; only if config/integrity.php exists)
        if ($this->configPath !== null && is_file($this->configPath . DIRECTORY_SEPARATOR . 'integrity.php')) {
            $integrityData = $this->loadConfigFile('integrity');
            $integrityConfig = IntegrityConfig::fromArray($integrityData, $this->environment);
            $this->repository->set($integrityConfig);
        }

        // Load deploy config (optional; only if config/deploy.php exists)
        if ($this->configPath !== null && is_file($this->configPath . DIRECTORY_SEPARATOR . 'deploy.php')) {
            $deployData = $this->loadConfigFile('deploy');
            $deployConfig = DeployConfig::fromArray($deployData, $this->environment);
            $this->repository->set($deployConfig);
        }

        // Load runtime config (optional; only if config/runtime.php exists)
        if ($this->configPath !== null && is_file($this->configPath . DIRECTORY_SEPARATOR . 'runtime.php')) {
            $runtimeData = $this->loadConfigFile('runtime');
            $runtimeConfig = RuntimeConfig::fromArray($runtimeData, $this->environment);
            $this->repository->set($runtimeConfig);
        }

        // Load cache config (optional; only if config/cache.php exists)
        if ($this->configPath !== null && is_file($this->configPath . DIRECTORY_SEPARATOR . 'cache.php')) {
            $cacheData = $this->loadConfigFile('cache');
            $cacheConfig = CacheConfig::fromArray($cacheData, $this->environment);
            $this->repository->set($cacheConfig);
        }

        // Load mail config (optional; only if config/mail.php exists)
        if ($this->configPath !== null && is_file($this->configPath . DIRECTORY_SEPARATOR . 'mail.php')) {
            $mailData = $this->loadConfigFile('mail');
            $mailConfig = MailConfig::fromArray($mailData, $this->environment);
            $this->repository->set($mailConfig);
        }

        // Load notification config (optional; only if config/notification.php exists)
        if ($this->configPath !== null && is_file($this->configPath . DIRECTORY_SEPARATOR . 'notification.php')) {
            $notificationData = $this->loadConfigFile('notification');
            $notificationConfig = NotificationConfig::fromArray($notificationData, $this->environment);
            $this->repository->set($notificationConfig);
        }

        // Load API config (optional; only if config/api.php exists)
        if ($this->configPath !== null && is_file($this->configPath . DIRECTORY_SEPARATOR . 'api.php')) {
            $apiData = $this->loadConfigFile('api');
            $apiConfig = ApiConfig::fromArray($apiData);
            $this->repository->set($apiConfig);
        }

        // Load view config (optional; only if config/view.php exists)
        if ($this->configPath !== null && is_file($this->configPath . DIRECTORY_SEPARATOR . 'view.php')) {
            $viewData = $this->loadConfigFile('view');
            $viewConfig = ViewConfig::fromArray($viewData);
            $this->repository->set($viewConfig);
        }

        // Load cloud config (optional; only if config/cloud.php exists)
        if ($this->configPath !== null && is_file($this->configPath . DIRECTORY_SEPARATOR . 'cloud.php')) {
            $cloudData = $this->loadConfigFile('cloud');
            $cloudConfig = CloudConfig::fromArray($cloudData);
            $this->repository->set($cloudConfig);
        }

        // Load business profile config (optional; only if config/business.php exists)
        if ($this->configPath !== null && is_file($this->configPath . DIRECTORY_SEPARATOR . 'business.php')) {
            $businessData = $this->loadConfigFile('business');
            $businessConfig = BusinessProfileConfig::fromArray($businessData, $this->environment);
            $this->repository->set($businessConfig);
        }

        // Studio config is NOT loaded here; it is loaded directly by Kernel::studioPreboot()
        // to avoid introducing a StudioConfig dependency in ConfigManager.

        // F4.10: walk extension-registered loaders. Each builds
        // its DTO from the corresponding config file (or skips
        // when optional + missing) and stores it in the
        // repository keyed by the DTO class-string. Extensions
        // can rely on every framework-shipped config already
        // being in the repository at this point.
        foreach ($this->extensionLoaders as $entry) {
            $basename = $entry['basename'];
            $optional = $entry['optional'];
            $loader = $entry['loader'];

            if ($this->configPath === null) {
                if ($optional) {
                    continue;
                }
                throw MissingConfigException::forFile($basename . '.php');
            }

            $filePath = $this->configPath . DIRECTORY_SEPARATOR . $basename . '.php';
            if (!is_file($filePath)) {
                if ($optional) {
                    continue;
                }
                throw MissingConfigException::forFile($basename . '.php');
            }

            $data = $this->loadConfigFile($basename);
            $config = $loader->load($data, $this->environment);
            $this->repository->set($config);
        }

        // One chokepoint for unknown-key detection across every loaded section
        // (framework + extensions): fail closed in strict mode, else stash the
        // findings for the boot reporter to log once the real logger exists.
        $this->auditUnknownConfigKeys();
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
     * Descriptions of unrecognized config keys found during the last load(),
     * empty in strict mode (there they abort the boot instead). A boot-time
     * reporter logs these once the real logger is available.
     *
     * @return list<string>
     */
    public function unknownConfigKeyWarnings(): array
    {
        return $this->unknownKeyWarnings;
    }

    /**
     * Resolve whether unknown config keys fail the boot.
     *
     * Precedence: explicit env `PULSAR_CONFIG_STRICT`, then `config.strict_keys`
     * in config/app.php, then the default — **warn**, not throw.
     *
     * Default warn is deliberate for a newly introduced check: shipping strict
     * on a freshly hand-enumerated key list would turn any missed-but-valid key
     * (or a legacy extra key in an existing deployment) into a production boot
     * failure on upgrade. Operators opt into fail-closed — `strict_keys: true`
     * or `PULSAR_CONFIG_STRICT=true`, ideally scoped to production — once they
     * trust their config. The default can flip to strict-in-production after the
     * enumeration has proven itself across a release.
     *
     * @param array<string, mixed> $appData
     */
    private function resolveStrictKeys(array $appData): bool
    {
        $envFlag = $this->environment?->get('PULSAR_CONFIG_STRICT');
        if ($envFlag !== null && $envFlag !== '') {
            return $envFlag === 'true' || $envFlag === '1';
        }

        if (
            isset($appData['config'])
            && is_array($appData['config'])
            && array_key_exists('strict_keys', $appData['config'])
        ) {
            return (bool) $appData['config']['strict_keys'];
        }

        return false;
    }

    /**
     * Sweep every loaded config DTO for unrecognized keys. In strict mode the
     * aggregated set aborts the boot; otherwise it is stashed for logging.
     */
    private function auditUnknownConfigKeys(): void
    {
        $this->unknownKeyWarnings = [];

        if ($this->repository === null) {
            return;
        }

        $descriptions = [];

        foreach ($this->repository->all() as $config) {
            if (!$config instanceof ReportsUnknownKeys) {
                continue;
            }

            $section = self::sectionLabel($config::class);

            foreach ($config->unknownConfigKeys() as $key) {
                $descriptions[] = sprintf('config section "%s": unrecognized key "%s" (ignored)', $section, $key);
            }
        }

        if ($descriptions === []) {
            return;
        }

        if ($this->strictKeys) {
            throw ConfigException::unknownKeys($descriptions);
        }

        $this->unknownKeyWarnings = $descriptions;
    }

    /**
     * Short, operator-facing label for a config DTO class, e.g.
     * `Pulsar\Config\SessionConfig` becomes `session`.
     *
     * @param class-string $class
     */
    private static function sectionLabel(string $class): string
    {
        $short = ($pos = strrpos($class, '\\')) === false ? $class : substr($class, $pos + 1);

        if (str_ends_with($short, 'Config')) {
            $short = substr($short, 0, -6);
        }

        return strtolower($short);
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
