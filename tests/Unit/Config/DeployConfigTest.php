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

    #[Test]
    public function defaultChecksIncludeAllThirteenEntries(): void
    {
        $config = new DeployConfig();

        self::assertCount(13, $config->checks);
        self::assertArrayHasKey('debug-mode', $config->checks);
        self::assertArrayHasKey('opcache', $config->checks);
        self::assertArrayHasKey('jit', $config->checks);
        self::assertArrayHasKey('cache-settings', $config->checks);
        self::assertArrayHasKey('filesystem-scan', $config->checks);
        self::assertArrayHasKey('security-headers', $config->checks);
        self::assertArrayHasKey('https-readiness', $config->checks);
        self::assertArrayHasKey('http3-readiness', $config->checks);
        self::assertArrayHasKey('health-endpoint', $config->checks);
        self::assertArrayHasKey('rate-limiting', $config->checks);
        self::assertArrayHasKey('request-size-limits', $config->checks);
        self::assertArrayHasKey('trusted-proxies', $config->checks);
        self::assertArrayHasKey('integrity', $config->checks);
    }

    #[Test]
    public function checkConfigReturnsDefaultForUnknownCheck(): void
    {
        $config = new DeployConfig();

        $result = $config->checkConfig('nonexistent');

        self::assertTrue($result['enabled']);
        self::assertSame('warn', $result['severity']);
    }

    #[Test]
    public function checkConfigReturnsConfiguredValues(): void
    {
        $config = new DeployConfig();

        $debugConfig = $config->checkConfig('debug-mode');

        self::assertTrue($debugConfig['enabled']);
        self::assertSame('fail', $debugConfig['severity']);
    }

    #[Test]
    public function isCheckEnabledReturnsTrueByDefault(): void
    {
        $config = new DeployConfig();

        self::assertTrue($config->isCheckEnabled('debug-mode'));
        self::assertTrue($config->isCheckEnabled('unknown-check'));
    }

    #[Test]
    public function isCheckEnabledReturnsFalseWhenDisabled(): void
    {
        $config = new DeployConfig(
            checks: ['debug-mode' => ['enabled' => false, 'severity' => 'fail']],
        );

        self::assertFalse($config->isCheckEnabled('debug-mode'));
    }

    #[Test]
    public function fromArrayParsesChecksSection(): void
    {
        $config = DeployConfig::fromArray([
            'checks' => [
                'debug-mode' => ['enabled' => false, 'severity' => 'warn'],
                'jit' => ['enabled' => true, 'severity' => 'fail'],
            ],
        ], $this->environment);

        self::assertFalse($config->isCheckEnabled('debug-mode'));
        self::assertSame('warn', $config->checkConfig('debug-mode')['severity']);
        self::assertSame('fail', $config->checkConfig('jit')['severity']);
    }

    #[Test]
    public function fromArrayUsesDefaultsWhenNoChecksProvided(): void
    {
        $config = DeployConfig::fromArray([], $this->environment);

        self::assertCount(13, $config->checks);
        self::assertSame('fail', $config->checkConfig('debug-mode')['severity']);
    }

    /**
     * F26.3 / F26.22: an env-supplied `severity=off` is ignored
     * in production. The file-configured (or default) severity
     * stays in force, so an attacker who controls the
     * orchestration env cannot silently neutralise a deploy gate.
     */
    #[Test]
    public function envSeverityOffIsIgnoredInProduction(): void
    {
        putenv('APP_ENV=production');
        putenv('DEPLOY_CHECK_DEBUG_MODE_SEVERITY=off');

        try {
            $config = DeployConfig::fromArray([], Environment::load());

            $debug = $config->checkConfig('debug-mode');
            self::assertSame('fail', $debug['severity']);
            self::assertTrue($debug['enabled']);
        } finally {
            putenv('APP_ENV');
            putenv('DEPLOY_CHECK_DEBUG_MODE_SEVERITY');
        }
    }

    /**
     * F26.3: the production guard does NOT affect non-`off`
     * overrides — `fail` and `warn` can still be set via env so
     * operators can tighten or maintain a check's severity.
     */
    #[Test]
    public function envSeverityFailIsHonoredInProduction(): void
    {
        putenv('APP_ENV=production');
        putenv('DEPLOY_CHECK_JIT_SEVERITY=fail');

        try {
            $config = DeployConfig::fromArray([], Environment::load());

            self::assertSame('fail', $config->checkConfig('jit')['severity']);
        } finally {
            putenv('APP_ENV');
            putenv('DEPLOY_CHECK_JIT_SEVERITY');
        }
    }

    /**
     * F26.3: outside production (local/staging), `severity=off`
     * remains a valid env override so developers can silence
     * checks while iterating.
     */
    #[Test]
    public function envSeverityOffIsHonoredInLocal(): void
    {
        putenv('APP_ENV=local');
        putenv('DEPLOY_CHECK_DEBUG_MODE_SEVERITY=off');

        try {
            $config = DeployConfig::fromArray([], Environment::load());

            $debug = $config->checkConfig('debug-mode');
            self::assertSame('off', $debug['severity']);
            self::assertFalse($debug['enabled']);
        } finally {
            putenv('APP_ENV');
            putenv('DEPLOY_CHECK_DEBUG_MODE_SEVERITY');
        }
    }
}
