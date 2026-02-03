<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Deploy\Check;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\DeployConfig;
use Pulsar\Deploy\Check\TrustedProxyCheck;
use Pulsar\Deploy\CheckSeverity;

#[CoversClass(TrustedProxyCheck::class)]
final class TrustedProxyCheckTest extends TestCase
{
    #[Test]
    public function it_returns_name(): void
    {
        $check = new TrustedProxyCheck(new DeployConfig());

        self::assertSame('trusted-proxies', $check->getName());
    }

    #[Test]
    public function it_returns_description(): void
    {
        $check = new TrustedProxyCheck(new DeployConfig());

        self::assertSame(
            'Validates trusted proxies are configured for reverse-proxy environments',
            $check->getDescription(),
        );
    }

    #[Test]
    public function it_passes_when_proxies_configured(): void
    {
        $config = new DeployConfig(trustedProxies: ['10.0.0.0/8', '172.16.0.0/12']);
        $check = new TrustedProxyCheck($config);

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Pass, $result->severity);
        self::assertStringContainsString('2 entries', $result->message);
    }

    #[Test]
    public function it_passes_with_single_proxy(): void
    {
        $config = new DeployConfig(trustedProxies: ['192.168.1.1']);
        $check = new TrustedProxyCheck($config);

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Pass, $result->severity);
        self::assertStringContainsString('1 entries', $result->message);
    }

    #[Test]
    public function it_warns_when_no_proxies_in_production(): void
    {
        $config = new DeployConfig(trustedProxies: []);
        $check = new TrustedProxyCheck($config);

        $result = $check->check('production');

        self::assertSame(CheckSeverity::Warning, $result->severity);
        self::assertStringContainsString('No trusted proxies configured', $result->message);
        self::assertNotEmpty($result->recommendations);
    }

    #[Test]
    public function it_warns_when_no_proxies_in_staging(): void
    {
        $config = new DeployConfig(trustedProxies: []);
        $check = new TrustedProxyCheck($config);

        $result = $check->check('staging');

        self::assertSame(CheckSeverity::Warning, $result->severity);
        self::assertStringContainsString('No trusted proxies configured', $result->message);
    }

    #[Test]
    public function it_passes_when_no_proxies_in_local(): void
    {
        $config = new DeployConfig(trustedProxies: []);
        $check = new TrustedProxyCheck($config);

        $result = $check->check('local');

        self::assertSame(CheckSeverity::Pass, $result->severity);
        self::assertStringContainsString('not required in local', $result->message);
    }

    #[Test]
    public function warning_recommendations_mention_config_file(): void
    {
        $config = new DeployConfig(trustedProxies: []);
        $check = new TrustedProxyCheck($config);

        $result = $check->check('production');

        $joined = implode(' ', $result->recommendations);
        self::assertStringContainsString('config/deploy.php', $joined);
        self::assertStringContainsString('trusted_proxies', $joined);
    }
}
