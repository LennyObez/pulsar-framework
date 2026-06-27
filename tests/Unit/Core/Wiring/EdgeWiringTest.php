<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\EdgeWiring;
use Pulsar\Edge\EdgeConfig;
use Pulsar\Edge\EdgeFunctionPipeline;
use Pulsar\Edge\EdgeMiddleware;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;

use function bin2hex;
use function file_put_contents;
use function mkdir;
use function random_bytes;
use function sys_get_temp_dir;

final class EdgeWiringTest extends TestCase
{
    #[Test]
    public function pipesEdgeMiddlewareWhenConfigured(): void
    {
        $container = new Container();
        $pipeline = new MiddlewarePipeline($container);
        $before = $pipeline->count();

        $this->wire(
            $container,
            $pipeline,
            "'enabled' => true, 'geo_redirects' => ['country_redirects' => ['DE' => '/de']]",
        );

        self::assertTrue($container->has(EdgeFunctionPipeline::class));
        self::assertTrue($container->has(EdgeMiddleware::class));
        self::assertSame($before + 1, $pipeline->count());
    }

    #[Test]
    public function bindsConfigButDoesNothingWhenDisabled(): void
    {
        $container = new Container();
        $pipeline = new MiddlewarePipeline($container);
        $before = $pipeline->count();

        $this->wire($container, $pipeline, "'enabled' => false, 'geo_redirects' => ['country_redirects' => ['DE' => '/de']]");

        self::assertTrue($container->has(EdgeConfig::class));
        self::assertFalse($container->has(EdgeMiddleware::class));
        self::assertSame($before, $pipeline->count());
    }

    #[Test]
    public function wiredEdgeMiddlewareRedirectsByGeo(): void
    {
        $container = new Container();
        $pipeline = new MiddlewarePipeline($container);

        $this->wire(
            $container,
            $pipeline,
            "'enabled' => true, 'geo_redirects' => ['country_redirects' => ['DE' => '/de']]",
        );

        $middleware = $container->get(EdgeMiddleware::class);
        self::assertInstanceOf(EdgeMiddleware::class, $middleware);

        $response = $pipeline->dispatch(
            new ServerRequest(method: 'GET', uri: '/', headers: ['CF-IPCountry' => 'DE']),
            static fn(): Response => Response::text('origin'),
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/de', $response->getHeaderLine('Location'));
    }

    private function wire(Container $container, MiddlewarePipeline $pipeline, string $body): void
    {
        $configPath = sys_get_temp_dir() . '/pulsar_edge_wiring_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);
        file_put_contents($configPath . '/edge.php', "<?php return [$body];");

        $configManager = new ConfigManager($configPath);

        new EdgeWiring()->wire($container, $configManager, $pipeline, new MiddlewareRegistry(), new Router());
    }
}
