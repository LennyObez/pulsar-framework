<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Plugins;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Extension\Cms\Config\CmsSecurityConfig;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Internal\Plugins\CmsPluginManager;
use Pulsar\Extension\Cms\Internal\Plugins\HookExecutionEngine;
use Pulsar\Extension\Cms\Plugins\CmsPluginRepositoryInterface;
use Pulsar\Extension\Cms\Plugins\HookRegistry;
use Pulsar\Extension\Cms\Plugins\InstalledCmsPlugin;
use Pulsar\Extension\Cms\Plugins\PluginManifestValidatorInterface;
use Pulsar\Extension\Cms\Plugins\PluginProvenanceVerifierInterface;
use Pulsar\Extension\Cms\Themes\ThemeArchiveExtractorInterface;

#[CoversClass(CmsPluginManager::class)]
final class CmsPluginManagerTest extends TestCase
{
    private InMemoryPluginRepository $repository;
    private CmsPluginManager $manager;
    private EventDispatcherInterface $eventDispatcher;

    protected function setUp(): void
    {
        $this->repository = new InMemoryPluginRepository();
        $this->eventDispatcher = $this->createStub(EventDispatcherInterface::class);
        $hookRegistry = new HookRegistry();
        $auditLogger = $this->createStub(AuditLoggerInterface::class);
        $logger = new NullLogger();
        $hookEngine = new HookExecutionEngine($hookRegistry, $auditLogger, $logger);

        $this->manager = new CmsPluginManager(
            repository: $this->repository,
            manifestValidator: $this->createStub(PluginManifestValidatorInterface::class),
            provenanceVerifier: $this->createStub(PluginProvenanceVerifierInterface::class),
            archiveExtractor: $this->createStub(ThemeArchiveExtractorInterface::class),
            config: new CmsSecurityConfig(),
            container: $this->createStub(ContainerInterface::class),
            hookRegistry: $hookRegistry,
            hookEngine: $hookEngine,
            eventDispatcher: $this->eventDispatcher,
            auditLogger: $auditLogger,
            logger: $logger,
        );
    }

    #[Test]
    public function enableThrowsWhenPluginNotFound(): void
    {
        $this->expectException(CmsException::class);
        $this->expectExceptionMessage('not found');

        $this->manager->enable('nonexistent', 'admin');
    }

    #[Test]
    public function enableThrowsWhenAlreadyEnabled(): void
    {
        $plugin = $this->createPlugin('p1', isEnabled: true);
        $this->repository->save($plugin);

        $this->expectException(CmsException::class);
        $this->expectExceptionMessage('already enabled');

        $this->manager->enable('p1', 'admin');
    }

    #[Test]
    public function enableReturnsEnabledPlugin(): void
    {
        $plugin = $this->createPlugin('p1', isEnabled: false);
        $this->repository->save($plugin);

        $result = $this->manager->enable('p1', 'admin');

        self::assertTrue($result->isEnabled);
        self::assertSame('admin', $result->enabledBy);
        self::assertNotNull($result->enabledAt);
    }

    #[Test]
    public function disableThrowsWhenPluginNotFound(): void
    {
        $this->expectException(CmsException::class);
        $this->expectExceptionMessage('not found');

        $this->manager->disable('nonexistent', 'admin');
    }

    #[Test]
    public function disableThrowsWhenNotEnabled(): void
    {
        $plugin = $this->createPlugin('p1', isEnabled: false);
        $this->repository->save($plugin);

        $this->expectException(CmsException::class);
        $this->expectExceptionMessage('not enabled');

        $this->manager->disable('p1', 'admin');
    }

    #[Test]
    public function disableReturnsDisabledPlugin(): void
    {
        $plugin = $this->createPlugin('p1', isEnabled: true);
        $this->repository->save($plugin);

        $result = $this->manager->disable('p1', 'admin');

        self::assertFalse($result->isEnabled);
        self::assertNotNull($result->disabledAt);
    }

    #[Test]
    public function deleteThrowsWhenPluginNotFound(): void
    {
        $this->expectException(CmsException::class);
        $this->expectExceptionMessage('not found');

        $this->manager->delete('nonexistent', 'admin', 'cleanup');
    }

    #[Test]
    public function deleteThrowsWhenPluginIsEnabled(): void
    {
        $plugin = $this->createPlugin('p1', isEnabled: true);
        $this->repository->save($plugin);

        $this->expectException(CmsException::class);
        $this->expectExceptionMessage('enabled plugin');

        $this->manager->delete('p1', 'admin', 'cleanup');
    }

    #[Test]
    public function deleteRemovesPluginRecord(): void
    {
        $plugin = $this->createPlugin('p1', isEnabled: false);
        $this->repository->save($plugin);

        $this->manager->delete('p1', 'admin', 'cleanup');

        self::assertNull($this->repository->findById('p1'));
    }

    #[Test]
    public function getInstalledReturnsList(): void
    {
        $this->repository->save($this->createPlugin('p1'));
        $this->repository->save($this->createPlugin('p2'));

        $result = $this->manager->getInstalled();

        self::assertCount(2, $result);
    }

    #[Test]
    public function bootAllWithNoPluginsDoesNothing(): void
    {
        // Should not throw
        $this->manager->bootAll();
        self::assertSame([], $this->manager->getInstalled());
    }

    private function createPlugin(string $id, bool $isEnabled = false): InstalledCmsPlugin
    {
        $now = new DateTimeImmutable();

        return new InstalledCmsPlugin(
            id: $id,
            tenantId: null,
            slug: "plugin-{$id}",
            displayName: "Plugin {$id}",
            version: '1.0.0',
            description: null,
            authorName: null,
            authorUrl: null,
            license: null,
            manifestHash: 'abc',
            packageHash: 'def',
            provenanceVerified: true,
            signatureVerified: false,
            capabilities: [],
            bootOrder: 0,
            isEnabled: $isEnabled,
            storagePath: "/tmp/test-plugins/{$id}",
            installedAt: $now,
            installedBy: 'admin',
            enabledAt: $isEnabled ? $now : null,
            enabledBy: $isEnabled ? 'admin' : null,
            disabledAt: null,
            deletedAt: null,
        );
    }
}

/**
 * @internal Test-only in-memory implementation
 */
final class InMemoryPluginRepository implements CmsPluginRepositoryInterface
{
    /** @var array<string, InstalledCmsPlugin> */
    private array $plugins = [];

    public function findById(string $id): ?InstalledCmsPlugin
    {
        return $this->plugins[$id] ?? null;
    }

    public function findBySlug(string $slug, ?string $tenantId = null): ?InstalledCmsPlugin
    {
        foreach ($this->plugins as $p) {
            if ($p->slug === $slug && ($tenantId === null || $p->tenantId === $tenantId)) {
                return $p;
            }
        }

        return null;
    }

    public function findAll(?string $tenantId = null): array
    {
        return array_values(array_filter(
            $this->plugins,
            static fn(InstalledCmsPlugin $p): bool => $tenantId === null || $p->tenantId === $tenantId,
        ));
    }

    public function findEnabled(?string $tenantId = null): array
    {
        return array_values(array_filter(
            $this->plugins,
            static fn(InstalledCmsPlugin $p): bool => $p->isEnabled && ($tenantId === null || $p->tenantId === $tenantId),
        ));
    }

    public function save(InstalledCmsPlugin $plugin): void
    {
        $this->plugins[$plugin->id] = $plugin;
    }

    public function delete(string $pluginId): void
    {
        unset($this->plugins[$pluginId]);
    }
}
