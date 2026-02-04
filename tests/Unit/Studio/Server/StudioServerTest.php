<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Server;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Config\StudioServerConfig;
use Pulsar\Extension\Studio\Server\StudioServer;

#[CoversClass(StudioServer::class)]
final class StudioServerTest extends TestCase
{
    #[Test]
    public function urlReturnsCorrectlyFormattedUrl(): void
    {
        $config = new StudioServerConfig(host: '127.0.0.1', port: 8585);
        $server = new StudioServer($config, '/path/to/document/root');

        $url = $server->url();

        self::assertSame('http://127.0.0.1:8585', $url);
    }

    #[Test]
    public function urlUsesCustomHostAndPort(): void
    {
        $config = new StudioServerConfig(host: 'localhost', port: 9000);
        $server = new StudioServer($config, '/path/to/document/root');

        $url = $server->url();

        self::assertSame('http://localhost:9000', $url);
    }

    #[Test]
    public function consoleUrlAppendsStudioConsolePath(): void
    {
        $config = new StudioServerConfig(host: '127.0.0.1', port: 8585);
        $server = new StudioServer($config, '/path/to/document/root');

        $consoleUrl = $server->consoleUrl();

        self::assertSame('http://127.0.0.1:8585/studio/console', $consoleUrl);
    }

    #[Test]
    public function consoleUrlWithCustomHostAndPort(): void
    {
        $config = new StudioServerConfig(host: '0.0.0.0', port: 3000);
        $server = new StudioServer($config, '/path/to/document/root');

        $consoleUrl = $server->consoleUrl();

        self::assertSame('http://0.0.0.0:3000/studio/console', $consoleUrl);
    }

    #[Test]
    public function constructorAcceptsRouterScript(): void
    {
        $config = new StudioServerConfig();
        $server = new StudioServer($config, '/path/to/root', '/path/to/router.php');

        // Server should be created successfully with router script
        self::assertSame('http://127.0.0.1:8585', $server->url());
    }

    #[Test]
    public function constructorAcceptsNullRouterScript(): void
    {
        $config = new StudioServerConfig();
        $server = new StudioServer($config, '/path/to/root', null);

        self::assertSame('http://127.0.0.1:8585', $server->url());
    }

    #[Test]
    public function urlWithIpv6Host(): void
    {
        $config = new StudioServerConfig(host: '::1', port: 8585);
        $server = new StudioServer($config, '/path/to/root');

        $url = $server->url();

        self::assertSame('http://::1:8585', $url);
    }

    #[Test]
    public function defaultConfigValues(): void
    {
        $config = new StudioServerConfig();
        $server = new StudioServer($config, '/path/to/root');

        self::assertSame('http://127.0.0.1:8585', $server->url());
    }
}
