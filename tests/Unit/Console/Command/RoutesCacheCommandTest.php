<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\CacheIntegrity;
use Pulsar\Cache\RouteCache;
use Pulsar\Console\Command\RoutesCacheCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Http\Method;
use Pulsar\Routing\Route;
use Pulsar\Routing\RouterInterface;
use Pulsar\Security\Crypto\HmacInterface;

use function bin2hex;
use function is_dir;
use function mkdir;
use function random_bytes;
use function sys_get_temp_dir;

#[CoversClass(RoutesCacheCommand::class)]
final class RoutesCacheCommandTest extends TestCase
{
    private string $cachePath;

    protected function setUp(): void
    {
        $this->cachePath = sys_get_temp_dir() . '/pulsar_test_route_cache_' . bin2hex(random_bytes(4));
        if (!is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0o755, true);
        }
    }

    #[Test]
    public function configuredWithCorrectName(): void
    {
        $router = $this->createStub(RouterInterface::class);
        $cache = $this->createRouteCache();

        $command = new RoutesCacheCommand($router, $cache, $this->cachePath);

        self::assertSame('routes:cache', $command->name);
        self::assertNotEmpty($command->description);
        self::assertArrayHasKey('encrypt', $command->options);
    }

    #[Test]
    public function emptyRouteListShowsWarning(): void
    {
        $router = $this->createStub(RouterInterface::class);
        $router->method('routes')->willReturn([]);

        $cache = $this->createRouteCache();

        $command = new RoutesCacheCommand($router, $cache, $this->cachePath);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('routes:cache'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('No routes registered', $output->buffer);
    }

    #[Test]
    public function successfulCacheWriteShowsStats(): void
    {
        $route = new Route([Method::GET], '/api/users', 'App\\Controller\\UserController');
        $router = $this->createStub(RouterInterface::class);
        $router->method('routes')->willReturn([$route]);

        $cache = $this->createRouteCache();

        $command = new RoutesCacheCommand($router, $cache, $this->cachePath);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('routes:cache'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Routes cached: 1 compiled, 0 skipped', $output->buffer);
    }

    #[Test]
    public function closureRoutesAreSkippedAndReported(): void
    {
        $serializableRoute = new Route([Method::GET], '/api/users', 'App\\Controller\\UserController');
        $closureRoute = new Route([Method::GET], '/debug/info', static fn() => 'ok');

        $router = $this->createStub(RouterInterface::class);
        $router->method('routes')->willReturn([$serializableRoute, $closureRoute]);

        $cache = $this->createRouteCache();

        $command = new RoutesCacheCommand($router, $cache, $this->cachePath);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('routes:cache'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('1 compiled, 1 skipped', $output->buffer);
        self::assertStringContainsString('/debug/info', $output->buffer);
        self::assertStringContainsString('Skipped routes', $output->buffer);
    }

    private function createRouteCache(): RouteCache
    {
        $hmac = $this->createStub(HmacInterface::class);
        $hmac->method('computeHex')->willReturn('test_hmac_signature');
        $hmac->method('verifyHex')->willReturn(true);

        $integrity = new CacheIntegrity($hmac, 'test_hmac_key');

        return new RouteCache($integrity);
    }
}
