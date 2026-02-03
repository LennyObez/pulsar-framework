<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\DeployConfig;
use Pulsar\Config\Environment;

#[CoversClass(DeployConfig::class)]
final class DeployConfigTest extends TestCase
{
    private Environment $environment;

    protected function setUp(): void
    {
        $this->environment = Environment::load();
    }

    #[Test]
    public function fromArrayWithFullData(): void
    {
        $data = [
            'trusted_proxies' => ['10.0.0.0/8', '172.16.0.0/12', '192.168.1.1'],
            'request_limits' => [
                'max_post_size_mb' => 16,
                'max_upload_size_mb' => 50,
            ],
            'http3' => [
                'enabled' => true,
                'alt_svc_max_age' => 172800,
            ],
        ];

        $config = DeployConfig::fromArray($data, $this->environment);

        self::assertSame(['10.0.0.0/8', '172.16.0.0/12', '192.168.1.1'], $config->trustedProxies);
        self::assertSame(16, $config->maxPostSizeMb);
        self::assertSame(50, $config->maxUploadSizeMb);
        self::assertTrue($config->http3Enabled);
        self::assertSame(172800, $config->http3AltSvcMaxAge);
    }

    #[Test]
    public function fromArrayWithDefaults(): void
    {
        $config = DeployConfig::fromArray([], $this->environment);

        self::assertSame([], $config->trustedProxies);
        self::assertSame(8, $config->maxPostSizeMb);
        self::assertSame(10, $config->maxUploadSizeMb);
        self::assertFalse($config->http3Enabled);
        self::assertSame(86400, $config->http3AltSvcMaxAge);
    }

    #[Test]
    public function partialDataFillsRemainingWithDefaults(): void
    {
        $data = [
            'trusted_proxies' => ['127.0.0.1'],
            'http3' => [
                'enabled' => true,
            ],
        ];

        $config = DeployConfig::fromArray($data, $this->environment);

        self::assertSame(['127.0.0.1'], $config->trustedProxies);
        self::assertSame(8, $config->maxPostSizeMb);
        self::assertSame(10, $config->maxUploadSizeMb);
        self::assertTrue($config->http3Enabled);
        self::assertSame(86400, $config->http3AltSvcMaxAge);
    }

    #[Test]
    public function constructorDefaults(): void
    {
        $config = new DeployConfig();

        self::assertSame([], $config->trustedProxies);
        self::assertSame(8, $config->maxPostSizeMb);
        self::assertSame(10, $config->maxUploadSizeMb);
        self::assertFalse($config->http3Enabled);
        self::assertSame(86400, $config->http3AltSvcMaxAge);
    }

    #[Test]
    public function emptyTrustedProxiesArrayFromEmptyConfig(): void
    {
        $config = DeployConfig::fromArray([
            'trusted_proxies' => [],
        ], $this->environment);

        self::assertSame([], $config->trustedProxies);
    }

    #[Test]
    public function requestLimitsWithOnlyPostSize(): void
    {
        $data = [
            'request_limits' => [
                'max_post_size_mb' => 32,
            ],
        ];

        $config = DeployConfig::fromArray($data, $this->environment);

        self::assertSame(32, $config->maxPostSizeMb);
        self::assertSame(10, $config->maxUploadSizeMb);
    }
}
