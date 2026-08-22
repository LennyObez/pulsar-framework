<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Server;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Environment;
use Pulsar\Extension\Studio\Config\StudioServerConfig;
use Pulsar\Extension\Studio\Server\StudioServer;

final class StudioServerTest extends TestCase
{
    private static function makeConfig(array $data = []): StudioServerConfig
    {
        return StudioServerConfig::fromArray($data, Environment::load());
    }

    #[Test]
    public function url_returns_correct_format(): void
    {
        $config = self::makeConfig(['host' => '127.0.0.1', 'port' => 9090]);
        $server = new StudioServer($config, '/var/www');

        self::assertSame('http://127.0.0.1:9090', $server->url());
    }

    #[Test]
    public function console_url_appends_path(): void
    {
        $config = self::makeConfig(['host' => 'localhost', 'port' => 8080]);
        $server = new StudioServer($config, '/var/www');

        self::assertSame('http://localhost:8080/studio/console', $server->consoleUrl());
    }

    #[Test]
    public function url_with_default_config(): void
    {
        $config = self::makeConfig();
        $server = new StudioServer($config, '/app');

        $url = $server->url();
        self::assertStringStartsWith('http://', $url);
        self::assertStringContainsString(':', $url);
    }
}
