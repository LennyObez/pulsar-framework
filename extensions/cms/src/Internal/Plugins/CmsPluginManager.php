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
use Pulsar\Extension\Cms\Internal\Themes\SafeArchiveExtractor;
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
use Pulsar\Extension\Cms\Themes\ThemeArchiveExtractorInterface;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

use function bin2hex;
use function class_exists;
use function count;
use function dechex;
use function dirname;
use function file_exists;
use function file_get_contents;
use function hash;
use function hash_file;
use function hexdec;
use function implode;
use function is_dir;
use function json_decode;
use function microtime;
use function random_bytes;
use function rmdir;
use function rtrim;
use function sprintf;
use function str_pad;
use function substr;
use function sys_get_temp_dir;
use function unlink;

use const JSON_THROW_ON_ERROR;
use const STR_PAD_LEFT;

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
            $manifestData = json_decode($manifestJson, true, 512, JSON_THROW_ON_ERROR);
            $manifest = PluginManifest::fromArray($manifestData);

            $validation = $this->manifestValidator->validate($manifest);

            if (!$validation->isValid) {
                throw CmsException::pluginManifestInvalid(implode('; ', $validation->errors));
            }

            // Step 4: Check for existing plugin with same slug
            $existing = $this->repository->findBySlug($manifest->slug, $tenantId);

            if ($existing !== null) {
                throw CmsException::pluginManifestInvalid(
                    "Plugin with slug '{$manifest->slug}' is already installed",
                );
            }

            // Step 5: Move to permanent storage
            $pluginStoragePath = rtrim($this->storagePath, '/') . '/' . $manifest->slug;
            $this->moveDirectory($tempDir, $pluginStoragePath);

            // Step 6: Compute manifest hash
            $manifestHash = hash('sha256', $manifestJson);

            // Step 7: Create the installed plugin record
            $pluginId = $this->generateId();

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
                "plugin:{$pluginId}",
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

        $enabled = new InstalledCmsPlugin(
            id: $plugin->id,
            tenantId: $plugin->tenantId,
            slug: $plugin->slug,
            displayName: $plugin->displayName,
            version: $plugin->version,
            description: $plugin->description,
            authorName: $plugin->authorName,
            authorUrl: $plugin->authorUrl,
            license: $plugin->license,
            manifestHash: $plugin->manifestHash,
            packageHash: $plugin->packageHash,
            provenanceVerified: $plugin->provenanceVerified,
            signatureVerified: $plugin->signatureVerified,
            capabilities: $plugin->capabilities,
            bootOrder: $plugin->bootOrder,
            isEnabled: true,
            storagePath: $plugin->storagePath,
            installedAt: $plugin->installedAt,
            installedBy: $plugin->installedBy,
            enabledAt: $now,
            enabledBy: $enabledBy,
            disabledAt: null,
            deletedAt: $plugin->deletedAt,
        );

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
            "plugin:{$pluginId}",
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

        $disabled = new InstalledCmsPlugin(
            id: $plugin->id,
            tenantId: $plugin->tenantId,
            slug: $plugin->slug,
            displayName: $plugin->displayName,
            version: $plugin->version,
            description: $plugin->description,
            authorName: $plugin->authorName,
            authorUrl: $plugin->authorUrl,
            license: $plugin->license,
            manifestHash: $plugin->manifestHash,
            packageHash: $plugin->packageHash,
            provenanceVerified: $plugin->provenanceVerified,
            signatureVerified: $plugin->signatureVerified,
            capabilities: $plugin->capabilities,
            bootOrder: $plugin->bootOrder,
            isEnabled: false,
            storagePath: $plugin->storagePath,
            installedAt: $plugin->installedAt,
            installedBy: $plugin->installedBy,
            enabledAt: $plugin->enabledAt,
            enabledBy: $plugin->enabledBy,
            disabledAt: new DateTimeImmutable(),
            deletedAt: $plugin->deletedAt,
        );

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
            "plugin:{$pluginId}",
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
            "plugin:{$pluginId}",
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
                new ScopedContainerProxy($this->container, $plugin->slug),
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
                    "plugin:{$plugin->id}",
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
                    "plugin:{$slug}",
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
        $manifestData = json_decode($manifestJson, true, 512, JSON_THROW_ON_ERROR);
        $manifest = PluginManifest::fromArray($manifestData);

        if ($manifest->entryPoint === null) {
            return null;
        }

        // Auto-load the plugin class file if present
        $classFile = $plugin->storagePath . '/src/' . str_replace('\\', '/', $manifest->entryPoint) . '.php';

        if (file_exists($classFile)) {
            require_once $classFile;
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

    private function generateId(): string
    {
        $time = (int) (microtime(true) * 1000);
        $hex = str_pad(dechex($time), 12, '0', STR_PAD_LEFT);
        $random = bin2hex(random_bytes(8));

        return sprintf(
            '%s-%s-7%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($random, 0, 3),
            dechex(0x80 | (hexdec(substr($random, 3, 2)) & 0x3F)) . substr($random, 5, 2),
            substr($random, 7, 12) . bin2hex(random_bytes(1)),
        );
    }
}
