<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\Container;
use Pulsar\Context\RequestContextHolder;
use Pulsar\Core\Wiring\RequestContextWiring;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;
use Random\Engine\Secure;
use Random\Randomizer;

#[CoversClass(RequestContextWiring::class)]
final class RequestContextWiringTest extends TestCase
{
    #[Test]
    public function wireRegistersRequestContextHolder(): void
    {
        $container = new Container();
        $container->instance(Randomizer::class, new Randomizer(new Secure()));
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager();
        $configManager->load();

        $wiring = new RequestContextWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertTrue($container->has(RequestContextHolder::class));
        self::assertInstanceOf(RequestContextHolder::class, $container->get(RequestContextHolder::class));
    }

    private function createConfigManager(): ConfigManager
    {
        $configPath = sys_get_temp_dir() . '/pulsar_reqctx_wiring_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];');
        file_put_contents($configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];');

        return new ConfigManager($configPath);
    }
}
