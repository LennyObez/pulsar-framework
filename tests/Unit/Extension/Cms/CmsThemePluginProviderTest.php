<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Container\Container;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Extension\Cms\CmsThemePluginProvider;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Internal\Persistence\CachePreviewSessionRepository;
use Pulsar\Extension\Cms\Internal\Persistence\InMemoryPreviewSessionRepository;
use Pulsar\Extension\Cms\LiveCss\CssOverrideRepositoryInterface;
use Pulsar\Extension\Cms\Plugins\CmsPluginRepositoryInterface;
use Pulsar\Extension\Cms\Themes\PreviewSessionRepositoryInterface;
use Pulsar\Extension\Cms\Themes\ThemeManagerInterface;
use Pulsar\Extension\Cms\Themes\ThemeRepositoryInterface;

#[CoversClass(CmsThemePluginProvider::class)]
final class CmsThemePluginProviderTest extends TestCase
{
    private function createContainer(bool $withTaggedCache = false, bool $withEventDispatcher = true): Container
    {
        $container = new Container();
        $container->instance(ConnectionInterface::class, $this->createStub(ConnectionInterface::class));
        $container->instance(CmsConfig::class, CmsConfig::fromArray([]));
        $container->instance(ThemeRepositoryInterface::class, $this->createStub(ThemeRepositoryInterface::class));
        $container->instance(CmsPluginRepositoryInterface::class, $this->createStub(CmsPluginRepositoryInterface::class));
        $container->instance(CssOverrideRepositoryInterface::class, $this->createStub(CssOverrideRepositoryInterface::class));

        if ($withTaggedCache) {
            $container->instance(TaggedCacheInterface::class, $this->createStub(TaggedCacheInterface::class));
        }

        if ($withEventDispatcher) {
            $container->instance(EventDispatcherInterface::class, $this->createStub(EventDispatcherInterface::class));
        }

        return $container;
    }

    #[Test]
    public function registersThemeManagerWithoutTaggedCache(): void
    {
        $container = $this->createContainer(withTaggedCache: false);

        $provider = new CmsThemePluginProvider();
        $provider->register($container);

        self::assertTrue($container->has(ThemeManagerInterface::class));
    }

    #[Test]
    public function registersThemeManagerWithTaggedCache(): void
    {
        $container = $this->createContainer(withTaggedCache: true);

        $provider = new CmsThemePluginProvider();
        $provider->register($container);

        self::assertTrue($container->has(ThemeManagerInterface::class));
    }

    #[Test]
    public function usesInMemoryPreviewSessionWithoutTaggedCache(): void
    {
        $container = $this->createContainer(withTaggedCache: false);

        $provider = new CmsThemePluginProvider();
        $provider->register($container);

        self::assertTrue($container->has(PreviewSessionRepositoryInterface::class));

        /** @var PreviewSessionRepositoryInterface $repo */
        $repo = $container->get(PreviewSessionRepositoryInterface::class);
        self::assertInstanceOf(InMemoryPreviewSessionRepository::class, $repo);
    }

    #[Test]
    public function usesCachePreviewSessionWithTaggedCache(): void
    {
        $container = $this->createContainer(withTaggedCache: true);

        $provider = new CmsThemePluginProvider();
        $provider->register($container);

        self::assertTrue($container->has(PreviewSessionRepositoryInterface::class));

        /** @var PreviewSessionRepositoryInterface $repo */
        $repo = $container->get(PreviewSessionRepositoryInterface::class);
        self::assertInstanceOf(CachePreviewSessionRepository::class, $repo);
    }

    #[Test]
    public function doesNotRegisterThemeManagerWithoutEventDispatcher(): void
    {
        $container = $this->createContainer(withEventDispatcher: false);

        $provider = new CmsThemePluginProvider();
        $provider->register($container);

        self::assertFalse($container->has(ThemeManagerInterface::class));
        // Preview session repository is still registered regardless
        self::assertTrue($container->has(PreviewSessionRepositoryInterface::class));
    }

    #[Test]
    public function earlyReturnWithoutDatabaseConnection(): void
    {
        $container = new Container();

        $provider = new CmsThemePluginProvider();
        $provider->register($container);

        self::assertFalse($container->has(ThemeManagerInterface::class));
        self::assertFalse($container->has(PreviewSessionRepositoryInterface::class));
    }
}
