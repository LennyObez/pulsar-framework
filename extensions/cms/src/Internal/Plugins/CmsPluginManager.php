<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Plugins;

use DateTimeImmutable;
use FilesystemIterator;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Extension\Cms\Config\CmsSecurityConfig;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Plugins\CmsPluginContext;
use Pulsar\Extension\Cms\Plugins\CmsPluginInterface;
use Pulsar\Extension\Cms\Plugins\CmsPluginManagerInterface;
use Pulsar\Extension\Cms\Plugins\CmsPluginRepositoryInterface;
use Pulsar\Extension\Cms\Plugins\Event\PluginDeleted;
use Pulsar\Extension\Cms\Plugins\Event\PluginDisabled;
use Pulsar\Extension\Cms\Plugins\Event\PluginEnabled;
use Pulsar\Extension\Cms\Plugins\Event\PluginInstalled;
use Pulsar\Extension\Cms\Plugins\HookRegistry;
use Pulsar\Extension\Cms\Plugins\InstalledCmsPlugin;
use Pulsar\Extension\Cms\Plugins\PluginManifest;
use Pulsar\Extension\Cms\Plugins\PluginManifestValidatorInterface;
use Pulsar\Extension\Cms\Plugins\PluginProvenanceVerifierInterface;
use Pulsar\Extension\Cms\Plugins\ScopedContainerProxy;
use Pulsar\Extension\Cms\Support\UuidGenerator;
use Pulsar\Extension\Cms\Themes\ThemeArchiveExtractorInterface;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

use function basename;
use function bin2hex;
use function class_exists;
use function count;
use function dirname;
use function file_exists;
use function file_get_contents;
use function hash;
use function hash_file;
use function implode;
use function is_dir;
use function json_decode;
use function random_bytes;
use function rmdir;
use function rtrim;
use function spl_autoload_register;
use function sprintf;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use function unlink;

use const JSON_THROW_ON_ERROR;

/**
 * Plugin lifecycle manager handling install, enable, disable, delete, and boot.
 *
 * Reuses SafeArchiveExtractor for Zip Slip protection. Tracks plugin failures
 * with a circuit breaker that auto-disables after 10 failures in 5 minutes.
 */
#[Internal(reason: 'Use CmsPluginManagerInterface for public API')]
final readonly class CmsPluginManager implements CmsPluginManagerInterface
{
    public function __construct(
        private CmsPluginRepositoryInterface $repository,
        private PluginManifestValidatorInterface $manifestValidator,
        private PluginProvenanceVerifierInterface $provenanceVerifier,
        private ThemeArchiveExtractorInterface $archiveExtractor,
        private CmsSecurityConfig $config,
        private ContainerInterface $container,
        private HookRegistry $hookRegistry,
        private HookExecutionEngine $hookEngine,
        private EventDispatcherInterface $eventDispatcher,
        private ?AuditLoggerInterface $auditLogger,
        private LoggerInterface $logger,
        private string $storagePath = 'storage/cms/plugins',
    ) {}

    public function install(string $archivePath, string $installedBy, ?string $tenantId = null): InstalledCmsPlugin
    {
        // Step 1: Verify provenance (SHA-256 + optional Ed25519 signature)
        $signaturePath = $archivePath . '.sig';
        $sigPath = file_exists($signaturePath) ? $signaturePath : null;

        $provenance = $this->provenanceVerifier->verify($archivePath, $sigPath);

        if (!$provenance->isAcceptable($this->config->requireSignedPlugins)) {
            throw CmsException::pluginProvenanceFailed(
                $provenance->error ?? 'Provenance verification failed',
            );
        }

        // Step 2: Extract to a temporary directory for manifest validation
        $packageHash = hash_file('sha256', $archivePath);

        if ($packageHash === false) {
            throw CmsException::pluginExtractionFailed('Failed to compute archive hash');
        }

        $tempDir = sys_get_temp_dir() . '/pulsar_plugin_' . bin2hex(random_bytes(8));
        $extractResult = $this->archiveExtractor->extract($archivePath, $tempDir);

        if (!$extractResult->success) {
            throw CmsException::pluginExtractionFailed(implode('; ', $extractResult->errors));
        }

        try {
            // Step 3: Read and validate plugin.json manifest
            $manifestPath = $tempDir . '/plugin.json';

            if (!file_exists($manifestPath)) {
                throw CmsException::pluginManifestInvalid('Missing plugin.json in archive root');
            }

            $manifestJson = file_get_contents($manifestPath);

            if ($manifestJson === false) {
                throw CmsException::pluginManifestInvalid('Cannot read plugin.json');
            }

            /** @var array<string, mixed> $manifestData */
            $manifestData = json_decode($manifestJson, true, flags: JSON_THROW_ON_ERROR);
            $manifest = PluginManifest::fromArray($manifestData);

            $validation = $this->manifestValidator->validate($manifest);

            if (!$validation->isValid) {
                throw CmsException::pluginManifestInvalid(implode('; ', $validation->errors));
            }

            // Step 4: Check for existing plugin with same slug
            $existing = $this->repository->findBySlug($manifest->slug, $tenantId);

            if ($existing !== null) {
                throw CmsException::pluginManifestInvalid(
                    "Plugin with slug '$manifest->slug' is already installed",
                );
            }

            // Step 5: Static analysis scan for dangerous function calls
            $securityWarnings = $this->scanPluginForDangerousCalls($tempDir);

            if ($securityWarnings !== []) {
                foreach ($securityWarnings as $warning) {
                    $this->logger->warning('Plugin security scan warning', [
                        'slug' => $manifest->slug,
                        'warning' => $warning,
                    ]);
                }

                $this->auditLogger?->log(
                    AuditEvent::SecurityEvent,
                    AuditOutcome::Success,
                    $installedBy,
                    'cms.plugin.security_warnings',
                    "plugin:$manifest->slug",
                    ['warnings' => $securityWarnings],
                );
            }

            // Step 6: Move to permanent storage
            $pluginStoragePath = rtrim($this->storagePath, '/') . '/' . $manifest->slug;
            $this->moveDirectory($tempDir, $pluginStoragePath);

            // Step 7: Compute manifest hash
            $manifestHash = hash('sha256', $manifestJson);

            // Step 8: Create the installed plugin record
            $pluginId = UuidGenerator::v7();

            $plugin = new InstalledCmsPlugin(
                id: $pluginId,
                tenantId: $tenantId,
                slug: $manifest->slug,
                displayName: $manifest->displayName,
                version: $manifest->version,
                description: $manifest->description,
                authorName: $manifest->authorName,
                authorUrl: $manifest->authorUrl,
                license: $manifest->license,
                manifestHash: $manifestHash,
                packageHash: $packageHash,
                provenanceVerified: $provenance->hashValid,
                signatureVerified: $provenance->signatureValid,
                capabilities: $manifest->capabilities,
                bootOrder: 0,
                isEnabled: false,
                storagePath: $pluginStoragePath,
                installedAt: new DateTimeImmutable(),
                installedBy: $installedBy,
                enabledAt: null,
                enabledBy: null,
                disabledAt: null,
                deletedAt: null,
            );

            $this->repository->save($plugin);

            $this->eventDispatcher->dispatch(new PluginInstalled(
                pluginId: $pluginId,
                name: $manifest->displayName,
                version: $manifest->version,
                installedBy: $installedBy,
            ));

            $this->auditLogger?->log(
                AuditEvent::DataModification,
                AuditOutcome::Success,
                $installedBy,
                'cms.plugin.installed',
                "plugin:$pluginId",
                ['slug' => $manifest->slug, 'version' => $manifest->version],
            );

            $this->logger->info('Plugin installed', [
                'id' => $pluginId,
                'slug' => $manifest->slug,
                'version' => $manifest->version,
            ]);

            return $plugin;
        } finally {
            // Clean up temp directory if it still exists (move failed or exception)
            if (is_dir($tempDir)) {
                $this->removeDirectory($tempDir);
            }
        }
    }

    public function enable(string $pluginId, string $enabledBy): InstalledCmsPlugin
    {
        $plugin = $this->repository->findById($pluginId);

        if ($plugin === null) {
            throw CmsException::pluginNotFound($pluginId);
        }

        if ($plugin->isEnabled) {
            throw CmsException::pluginAlreadyEnabled($pluginId);
        }

        $now = new DateTimeImmutable();

        $enabled = $plugin->enable($enabledBy, $now);

        $this->repository->save($enabled);

        $this->eventDispatcher->dispatch(new PluginEnabled(
            pluginId: $pluginId,
            enabledBy: $enabledBy,
        ));

        $this->auditLogger?->log(
            AuditEvent::DataModification,
            AuditOutcome::Success,
            $enabledBy,
            'cms.plugin.enabled',
            "plugin:$pluginId",
            ['slug' => $plugin->slug],
        );

        $this->logger->info('Plugin enabled', [
            'id' => $pluginId,
            'slug' => $plugin->slug,
        ]);

        return $enabled;
    }

    public function disable(string $pluginId, string $disabledBy): InstalledCmsPlugin
    {
        $plugin = $this->repository->findById($pluginId);

        if ($plugin === null) {
            throw CmsException::pluginNotFound($pluginId);
        }

        if (!$plugin->isEnabled) {
            throw CmsException::pluginNotEnabled($pluginId);
        }

        $disabled = $plugin->disable(new DateTimeImmutable());

        $this->repository->save($disabled);

        $this->eventDispatcher->dispatch(new PluginDisabled(
            pluginId: $pluginId,
            disabledBy: $disabledBy,
        ));

        $this->auditLogger?->log(
            AuditEvent::DataModification,
            AuditOutcome::Success,
            $disabledBy,
            'cms.plugin.disabled',
            "plugin:$pluginId",
            ['slug' => $plugin->slug],
        );

        $this->logger->info('Plugin disabled', [
            'id' => $pluginId,
            'slug' => $plugin->slug,
        ]);

        return $disabled;
    }

    public function delete(string $pluginId, string $deletedBy, string $reason): void
    {
        $plugin = $this->repository->findById($pluginId);

        if ($plugin === null) {
            throw CmsException::pluginNotFound($pluginId);
        }

        if ($plugin->isEnabled) {
            throw CmsException::pluginIsEnabled($pluginId);
        }

        // Remove plugin storage directory
        if (is_dir($plugin->storagePath)) {
            $this->removeDirectory($plugin->storagePath);
        }

        // Soft delete the record
        $this->repository->delete($pluginId);

        $this->eventDispatcher->dispatch(new PluginDeleted(
            pluginId: $pluginId,
            deletedBy: $deletedBy,
            reason: $reason,
        ));

        $this->auditLogger?->log(
            AuditEvent::DataModification,
            AuditOutcome::Success,
            $deletedBy,
            'cms.plugin.deleted',
            "plugin:$pluginId",
            ['slug' => $plugin->slug, 'reason' => $reason],
        );

        $this->logger->info('Plugin deleted', [
            'id' => $pluginId,
            'slug' => $plugin->slug,
            'reason' => $reason,
        ]);
    }

    public function bootAll(?string $tenantId = null): void
    {
        $plugins = $this->repository->findEnabled($tenantId);

        if ($plugins === []) {
            return;
        }

        // Resolve boot order (already sorted by boot_order from repository)
        // Phase 1: register() on all plugins
        $contexts = [];

        foreach ($plugins as $plugin) {
            if ($this->hookEngine->isCircuitBroken($plugin->slug)) {
                $this->logger->warning('Skipping circuit-broken plugin during boot', [
                    'slug' => $plugin->slug,
                ]);

                continue;
            }

            $context = new CmsPluginContext(
                $plugin->slug,
                new ScopedContainerProxy($this->container, $plugin->slug, $plugin->capabilities),
                $this->hookRegistry,
            );

            $instance = $this->loadPluginInstance($plugin);

            if ($instance === null) {
                continue;
            }

            try {
                $instance->register($context);
                $contexts[$plugin->slug] = ['instance' => $instance, 'context' => $context];
            } catch (Throwable $e) {
                $this->logger->error('Plugin register() failed', [
                    'slug' => $plugin->slug,
                    'error' => $e->getMessage(),
                ]);

                $this->auditLogger?->log(
                    AuditEvent::SystemEvent,
                    AuditOutcome::Error,
                    null,
                    'cms.plugin.register_failed',
                    "plugin:$plugin->id",
                    ['slug' => $plugin->slug, 'error' => $e->getMessage()],
                );
            }
        }

        // Phase 2: boot() on all registered plugins
        foreach ($contexts as $slug => $entry) {
            if ($this->hookEngine->isCircuitBroken($slug)) {
                continue;
            }

            try {
                $entry['instance']->boot($entry['context']);
            } catch (Throwable $e) {
                $this->logger->error('Plugin boot() failed', [
                    'slug' => $slug,
                    'error' => $e->getMessage(),
                ]);

                $this->auditLogger?->log(
                    AuditEvent::SystemEvent,
                    AuditOutcome::Error,
                    null,
                    'cms.plugin.boot_failed',
                    "plugin:$slug",
                    ['error' => $e->getMessage()],
                );
            }
        }

        $this->logger->info('Plugin boot completed', [
            'total_plugins' => count($plugins),
            'booted' => count($contexts),
        ]);
    }

    public function getInstalled(?string $tenantId = null): array
    {
        return $this->repository->findAll($tenantId);
    }

    /**
     * Load and instantiate the plugin entry point class.
     *
     * Loading priority:
     * 1. Composer vendor autoloader (vendor/autoload.php in plugin directory)
     * 2. Already-autoloadable class (installed via Composer require)
     * 3. PSR-4 autoload mappings from plugin manifest
     * 4. Fallback: direct file require from src/ directory
     */
    private function loadPluginInstance(InstalledCmsPlugin $plugin): ?CmsPluginInterface
    {
        // Read manifest to get entry point
        $manifestPath = $plugin->storagePath . '/plugin.json';

        if (!file_exists($manifestPath)) {
            $this->logger->error('Plugin manifest not found during boot', [
                'slug' => $plugin->slug,
                'path' => $manifestPath,
            ]);

            return null;
        }

        $manifestJson = file_get_contents($manifestPath);

        if ($manifestJson === false) {
            return null;
        }

        /** @var array<string, mixed> $manifestData */
        $manifestData = json_decode($manifestJson, true, flags: JSON_THROW_ON_ERROR);
        $manifest = PluginManifest::fromArray($manifestData);

        if ($manifest->entryPoint === null) {
            return null;
        }

        // 1. Try vendor autoloader (Composer-managed plugin)
        $vendorAutoload = $plugin->storagePath . '/vendor/autoload.php';

        if (file_exists($vendorAutoload)) {
            require_once $vendorAutoload;
        }

        // 2. Check if class is already autoloadable
        if (!class_exists($manifest->entryPoint)) {
            // 3. Try PSR-4 autoload from manifest
            if ($manifest->autoload !== null && isset($manifest->autoload['psr-4'])) {
                $this->registerPsr4Autoloader($plugin->storagePath, $manifest->autoload['psr-4']);
            }

            // 4. Fallback: direct file require
            if (!class_exists($manifest->entryPoint)) {
                $classFile = $plugin->storagePath . '/src/' . str_replace('\\', '/', $manifest->entryPoint) . '.php';

                if (file_exists($classFile)) {
                    require_once $classFile;
                }
            }
        }

        if (!class_exists($manifest->entryPoint)) {
            $this->logger->error('Plugin entry point class not found', [
                'slug' => $plugin->slug,
                'entry_point' => $manifest->entryPoint,
            ]);

            return null;
        }

        $instance = new ($manifest->entryPoint)();

        if (!$instance instanceof CmsPluginInterface) {
            $this->logger->error('Plugin entry point does not implement CmsPluginInterface', [
                'slug' => $plugin->slug,
                'entry_point' => $manifest->entryPoint,
            ]);

            return null;
        }

        return $instance;
    }

    /**
     * Register a PSR-4 autoloader for a plugin's namespace mappings.
     *
     * @param string $basePath Plugin storage path
     * @param array<string, string> $mappings Namespace prefix => directory mapping
     */
    private function registerPsr4Autoloader(string $basePath, array $mappings): void
    {
        spl_autoload_register(static function (string $class) use ($basePath, $mappings): void {
            foreach ($mappings as $prefix => $directory) {
                if (!str_starts_with($class, $prefix)) {
                    continue;
                }

                $relativeClass = substr($class, strlen($prefix));
                $file = $basePath . '/' . rtrim($directory, '/') . '/' . str_replace('\\', '/', $relativeClass) . '.php';

                if (file_exists($file)) {
                    require_once $file;

                    return;
                }
            }
        });
    }

    /**
     * Scan a plugin directory for potentially dangerous function calls.
     *
     * @return list<string> Security warnings found in plugin source files
     */
    private function scanPluginForDangerousCalls(string $directory): array
    {
        $warnings = [];
        $dangerousFunctions = [
            'eval', 'exec', 'system', 'passthru', 'shell_exec',
            'proc_open', 'popen', 'curl_exec',
        ];

        $srcDir = $directory . '/src';

        if (!is_dir($srcDir)) {
            return [];
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $item) {
            /** @var SplFileInfo $item */
            if ($item->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($item->getPathname());

            if ($content === false) {
                continue;
            }

            $filename = basename($item->getPathname());

            foreach ($dangerousFunctions as $func) {
                if (str_contains($content, $func . '(')) {
                    $warnings[] = sprintf(
                        'Plugin uses potentially dangerous function %s() in %s',
                        $func,
                        $filename,
                    );
                }
            }

            // Detect file_get_contents() with URL strings (remote file access)
            if (
                str_contains($content, 'file_get_contents(')
                && (str_contains($content, 'http://') || str_contains($content, 'https://'))
            ) {
                $warnings[] = sprintf(
                    'Plugin may use file_get_contents() with remote URLs in %s',
                    $filename,
                );
            }
        }

        return $warnings;
    }

    private function moveDirectory(string $source, string $target): void
    {
        $parentDir = dirname($target);

        if (!is_dir($parentDir)) {
            mkdir($parentDir, 0o755, true);
        }

        rename($source, $target);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            /** @var SplFileInfo $item */
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }
}
