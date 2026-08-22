<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Environment;
use Pulsar\Extension\Studio\Config\StudioServerConfig;

final class StudioServerConfigTest extends TestCase
{
    #[Test]
    public function defaultValues(): void
    {
        $config = new StudioServerConfig();

        self::assertSame('127.0.0.1', $config->host);
        self::assertSame(8585, $config->port);
        self::assertSame('extensions/studio/dev/public', $config->documentRoot);
    }

    #[Test]
    public function fromArrayReadsHost(): void
    {
        $env = Environment::load();
        $config = StudioServerConfig::fromArray(['host' => '0.0.0.0'], $env);

        self::assertSame('0.0.0.0', $config->host);
    }

    #[Test]
    public function fromArrayReadsPort(): void
    {
        $env = Environment::load();
        $config = StudioServerConfig::fromArray(['port' => 9090], $env);

        self::assertSame(9090, $config->port);
    }

    #[Test]
    public function fromArrayReadsDocumentRoot(): void
    {
        $env = Environment::load();
        $config = StudioServerConfig::fromArray(['document_root' => '/var/www/studio'], $env);

        self::assertSame('/var/www/studio', $config->documentRoot);
    }
}
