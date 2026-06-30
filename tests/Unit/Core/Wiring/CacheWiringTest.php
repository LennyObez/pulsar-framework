<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\SimpleCache\CacheInterface;
use Pulsar\Cache\Application\CacheManager;
use Pulsar\Cache\Application\CacheManagerInterface;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Config\CacheConfig;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\CacheWiring;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;

use function bin2hex;
use function file_put_contents;
use function mkdir;
use function random_bytes;

#[CoversClass(CacheWiring::class)]
final class CacheWiringTest extends TestCase
{
    #[Test]
    public function wireRegistersCacheServicesWhenEnabled(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(enabled: true);
        $configManager->load();

        $wiring = new CacheWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertTrue($container->has(CacheConfig::class));
        self::assertTrue($container->has(CacheManager::class));
        self::assertTrue($container->has(CacheManagerInterface::class));
        self::assertTrue($container->has(CacheItemPoolInterface::class));
        self::assertTrue($container->has(CacheInterface::class));
        // Regression: the tagged cache must be bound too, otherwise every
        // tag-aware consumer wired later (anti-spam single-use, duplicate
        // detection, reputation cooldowns) silently disables itself.
        self::assertTrue($container->has(TaggedCacheInterface::class));
    }

    #[Test]
    public function wireBindsAResolvableTaggedCacheWhenEnabled(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(enabled: true);
        $configManager->load();

        new CacheWiring()->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        $tagged = $container->get(TaggedCacheInterface::class);
        self::assertInstanceOf(TaggedCacheInterface::class, $tagged);

        // It is a working tagged cache, not a placeholder: a tagged write is
        // retrievable and is dropped when its tag is flushed.
        $tagged->set('k', 'v', ['t'], 60);
        self::assertSame('v', $tagged->get('k'));
        $tagged->invalidateTag('t');
        self::assertNull($tagged->get('k'));
    }

    #[Test]
    public function wireDoesNotBindTaggedCacheWhenDisabled(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(enabled: false);
        $configManager->load();

        new CacheWiring()->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertFalse($container->has(TaggedCacheInterface::class));
    }

    #[Test]
    public function wireSkipsWhenDisabled(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(enabled: false);
        $configManager->load();

        $wiring = new CacheWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertTrue($container->has(CacheConfig::class));
        self::assertFalse($container->has(CacheManager::class));
    }

    #[Test]
    public function wireSkipsWhenNoConfig(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManagerWithout();
        $configManager->load();

        $wiring = new CacheWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertFalse($container->has(CacheConfig::class));
    }

    private function createConfigManager(bool $enabled): ConfigManager
    {
        $configPath = sys_get_temp_dir() . '/pulsar_cache_wiring_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        $enabledStr = $enabled ? 'true' : 'false';
        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];');
        file_put_contents($configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];');
        file_put_contents($configPath . '/cache.php', '<?php return ["enabled" => ' . $enabledStr . '];');

        return new ConfigManager($configPath);
    }

    private function createConfigManagerWithout(): ConfigManager
    {
        $configPath = sys_get_temp_dir() . '/pulsar_cache_wiring_no_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];');
        file_put_contents($configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];');

        return new ConfigManager($configPath);
    }
}
