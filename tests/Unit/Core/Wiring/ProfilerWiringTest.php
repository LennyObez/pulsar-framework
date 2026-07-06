<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\ProfilerWiring;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Observability\Profiler\ProfilerConfig;
use Pulsar\Observability\Profiler\ProfilerMiddleware;
use Pulsar\Observability\Profiler\RequestProfiler;
use Pulsar\Routing\Router;

use function bin2hex;
use function file_put_contents;
use function mkdir;
use function random_bytes;
use function sys_get_temp_dir;

final class ProfilerWiringTest extends TestCase
{
    #[Test]
    public function bindsProfilerAndPipesMiddlewareWhenEnabled(): void
    {
        $container = new Container();
        $pipeline = new MiddlewarePipeline($container);
        $before = $pipeline->count();

        $this->wire($container, $pipeline, "'enabled' => true, 'max_entries' => 100");

        self::assertTrue($container->has(RequestProfiler::class));
        self::assertTrue($container->has(ProfilerMiddleware::class));
        self::assertSame($before + 1, $pipeline->count());

        $profiler = $container->get(RequestProfiler::class);
        self::assertInstanceOf(RequestProfiler::class, $profiler);
        self::assertTrue($profiler->isEnabled());
    }

    #[Test]
    public function bindsConfigButDoesNothingWhenDisabled(): void
    {
        $container = new Container();
        $pipeline = new MiddlewarePipeline($container);
        $before = $pipeline->count();

        $this->wire($container, $pipeline, "'enabled' => false");

        self::assertTrue($container->has(ProfilerConfig::class), 'config is bound for introspection');
        self::assertFalse($container->has(RequestProfiler::class));
        self::assertSame($before, $pipeline->count());
    }

    private function wire(Container $container, MiddlewarePipeline $pipeline, string $body): void
    {
        $configPath = sys_get_temp_dir() . '/pulsar_profiler_wiring_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);
        file_put_contents($configPath . '/profiler.php', "<?php return [$body];");

        $configManager = new ConfigManager($configPath);

        new ProfilerWiring()->wire($container, $configManager, $pipeline, new MiddlewareRegistry(), new Router());
    }
}
