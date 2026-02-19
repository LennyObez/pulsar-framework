<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Plugins;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Auth\Authorization\RoleRegistryInterface;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Media\MediaRepositoryInterface;
use Pulsar\Extension\Cms\Plugins\ScopedContainerProxy;
use Pulsar\Extension\Cms\Settings\SettingsServiceInterface;
use Pulsar\Security\Crypto\MasterKey;
use RuntimeException;
use stdClass;

#[CoversClass(ScopedContainerProxy::class)]
final class CmsPluginIsolationTest extends TestCase
{
    private ContainerInterface&Stub $container;
    private ScopedContainerProxy $proxy;

    protected function setUp(): void
    {
        $this->container = $this->createStub(ContainerInterface::class);
        $this->proxy = new ScopedContainerProxy($this->container, 'test-plugin');
    }

    // -- Allowed services -----------------------------------------------------

    #[Test]
    public function test_allows_logger_interface_resolution(): void
    {
        $logger = $this->createStub(LoggerInterface::class);
        $this->stubContainerService(LoggerInterface::class, $logger);

        $result = $this->proxy->get(LoggerInterface::class);

        self::assertSame($logger, $result);
    }

    #[Test]
    public function test_allows_tagged_cache_interface_resolution(): void
    {
        $cache = $this->createStub(TaggedCacheInterface::class);
        $this->stubContainerService(TaggedCacheInterface::class, $cache);

        $result = $this->proxy->get(TaggedCacheInterface::class);

        self::assertSame($cache, $result);
    }

    #[Test]
    public function test_allows_content_repository_interface_resolution(): void
    {
        $repo = $this->createStub(ContentRepositoryInterface::class);
        $this->stubContainerService(ContentRepositoryInterface::class, $repo);

        $result = $this->proxy->get(ContentRepositoryInterface::class);

        self::assertSame($repo, $result);
    }

    #[Test]
    public function test_allows_event_dispatcher_resolution(): void
    {
        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $this->stubContainerService(EventDispatcherInterface::class, $dispatcher);

        $result = $this->proxy->get(EventDispatcherInterface::class);

        self::assertSame($dispatcher, $result);
    }

    #[Test]
    public function test_allows_media_repository_resolution(): void
    {
        $media = $this->createStub(MediaRepositoryInterface::class);
        $this->stubContainerService(MediaRepositoryInterface::class, $media);

        $result = $this->proxy->get(MediaRepositoryInterface::class);

        self::assertSame($media, $result);
    }

    #[Test]
    public function test_allows_settings_service_resolution(): void
    {
        $settings = $this->createStub(SettingsServiceInterface::class);
        $this->stubContainerService(SettingsServiceInterface::class, $settings);

        $result = $this->proxy->get(SettingsServiceInterface::class);

        self::assertSame($settings, $result);
    }

    // -- Denied services: security-sensitive ----------------------------------

    #[Test]
    public function test_denies_master_key_resolution(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not allowed');

        $this->proxy->get(MasterKey::class);
    }

    #[Test]
    public function test_denies_audit_logger_interface_resolution(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not allowed');

        $this->proxy->get(AuditLoggerInterface::class);
    }

    #[Test]
    public function test_denies_role_registry_interface_resolution(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not allowed');

        $this->proxy->get(RoleRegistryInterface::class);
    }

    #[Test]
    public function test_denies_container_interface_resolution(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not allowed');

        $this->proxy->get(ContainerInterface::class);
    }

    #[Test]
    public function test_denies_arbitrary_class_resolution(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not allowed');

        $this->proxy->get(stdClass::class);
    }

    // -- Error message includes plugin slug -----------------------------------

    #[Test]
    public function test_denial_error_includes_plugin_slug(): void
    {
        try {
            $this->proxy->get(AuditLoggerInterface::class);
            self::fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('test-plugin', $e->getMessage());
        }
    }

    // -- has() method ---------------------------------------------------------

    #[Test]
    public function test_has_returns_true_for_allowed_and_registered(): void
    {
        $this->container->method('has')
            ->willReturnCallback(static fn(string $id): bool => $id === LoggerInterface::class);

        self::assertTrue($this->proxy->has(LoggerInterface::class));
    }

    #[Test]
    public function test_has_returns_false_for_denied_service(): void
    {
        self::assertFalse($this->proxy->has(AuditLoggerInterface::class));
    }

    #[Test]
    public function test_has_returns_false_for_allowed_but_not_registered(): void
    {
        $this->container->method('has')
            ->willReturn(false);

        self::assertFalse($this->proxy->has(LoggerInterface::class));
    }

    // -- Service not in container throws -------------------------------------

    #[Test]
    public function test_allowed_service_not_in_container_throws(): void
    {
        $this->container->method('has')
            ->willReturn(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not available');

        $this->proxy->get(LoggerInterface::class);
    }

    // -- Helpers --------------------------------------------------------------

    private function stubContainerService(string $id, object $instance): void
    {
        $this->container->method('has')
            ->willReturnCallback(static fn(string $serviceId): bool => $serviceId === $id);

        $this->container->method('get')
            ->willReturnCallback(static fn(string $serviceId): object => $serviceId === $id
                ? $instance
                : throw new RuntimeException("Unexpected service: {$serviceId}"));
    }
}
