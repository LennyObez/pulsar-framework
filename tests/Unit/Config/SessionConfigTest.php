<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Environment;
use Pulsar\Config\Exception\ConfigException;
use Pulsar\Config\SessionConfig;

#[CoversClass(SessionConfig::class)]
final class SessionConfigTest extends TestCase
{
    #[Test]
    public function constructorWithAllFields(): void
    {
        $config = new SessionConfig(
            cookieName: 'MY_SESSION',
            lifetime: 1800,
            cookieHttpOnly: true,
            cookieSecure: true,
            cookieSameSite: 'Strict',
            regenerateOnPrivilegeChange: true,
            handler: 'redis',
            encryption: true,
            validators: [
                'user_agent' => ['enabled' => true, 'mode' => 'normalized'],
            ],
            maxConcurrentSessions: 5,
            cookiePath: '/app',
            cookieDomain: 'example.com',
            gcProbability: 2,
            gcDivisor: 50,
            savePath: '/tmp/sessions',
            cookieMaxPayloadSize: 1024,
            cookieReplayWindow: 43200,
        );

        self::assertSame('MY_SESSION', $config->cookieName);
        self::assertSame(1800, $config->lifetime);
        self::assertTrue($config->cookieHttpOnly);
        self::assertTrue($config->cookieSecure);
        self::assertSame('Strict', $config->cookieSameSite);
        self::assertTrue($config->regenerateOnPrivilegeChange);
        self::assertSame('redis', $config->handler);
        self::assertTrue($config->encryption);
        self::assertSame(5, $config->maxConcurrentSessions);
        self::assertSame('/app', $config->cookiePath);
        self::assertSame('example.com', $config->cookieDomain);
        self::assertSame(2, $config->gcProbability);
        self::assertSame(50, $config->gcDivisor);
        self::assertSame('/tmp/sessions', $config->savePath);
        self::assertSame(1024, $config->cookieMaxPayloadSize);
        self::assertSame(43200, $config->cookieReplayWindow);
    }

    #[Test]
    public function defaultValues(): void
    {
        $config = new SessionConfig(
            cookieName: 'TEST',
            lifetime: 3600,
            cookieHttpOnly: true,
            cookieSecure: true,
            cookieSameSite: 'Lax',
            regenerateOnPrivilegeChange: true,
        );

        self::assertSame('file', $config->handler);
        self::assertTrue($config->encryption);
        self::assertSame([], $config->validators);
        self::assertSame(3, $config->maxConcurrentSessions);
        self::assertSame('/', $config->cookiePath);
        self::assertSame('', $config->cookieDomain);
        self::assertSame(1, $config->gcProbability);
        self::assertSame(100, $config->gcDivisor);
        self::assertSame('', $config->savePath);
        self::assertSame(2048, $config->cookieMaxPayloadSize);
        self::assertSame(86400, $config->cookieReplayWindow);
    }

    #[Test]
    public function fromArrayWithAllFields(): void
    {
        $env = Environment::load();

        $config = SessionConfig::fromArray([
            'cookie_name' => 'APP_SESSION',
            'lifetime' => 7200,
            'cookie_httponly' => true,
            'cookie_secure' => false,
            'cookie_samesite' => 'Lax',
            'regenerate_on_privilege_change' => false,
            'handler' => 'database',
            'encryption' => false,
            'max_concurrent_sessions' => 10,
            'cookie_path' => '/admin',
            'cookie_domain' => '.example.org',
            'gc_probability' => 5,
            'gc_divisor' => 200,
            'save_path' => '/var/sessions',
            'cookie_max_payload_size' => 4096,
            'cookie_replay_window' => 3600,
        ], $env);

        self::assertSame(7200, $config->lifetime);
        self::assertTrue($config->cookieHttpOnly);
        self::assertFalse($config->cookieSecure);
        self::assertSame('Lax', $config->cookieSameSite);
        self::assertFalse($config->regenerateOnPrivilegeChange);
        self::assertSame('database', $config->handler);
        self::assertFalse($config->encryption);
        self::assertSame(10, $config->maxConcurrentSessions);
        self::assertSame('/admin', $config->cookiePath);
        self::assertSame('.example.org', $config->cookieDomain);
        self::assertSame(5, $config->gcProbability);
        self::assertSame(200, $config->gcDivisor);
        self::assertSame('/var/sessions', $config->savePath);
        self::assertSame(4096, $config->cookieMaxPayloadSize);
        self::assertSame(3600, $config->cookieReplayWindow);
    }

    #[Test]
    public function fromArrayDefaults(): void
    {
        $env = Environment::load();

        $config = SessionConfig::fromArray([], $env);

        self::assertSame('PULSAR_SESSION', $config->cookieName);
        self::assertSame(7200, $config->lifetime);
        self::assertTrue($config->cookieHttpOnly);
        self::assertTrue($config->cookieSecure);
        self::assertSame('Strict', $config->cookieSameSite);
        self::assertTrue($config->regenerateOnPrivilegeChange);
        self::assertSame('file', $config->handler);
        self::assertTrue($config->encryption);
        self::assertSame(3, $config->maxConcurrentSessions);
        self::assertSame('/', $config->cookiePath);
        self::assertSame('', $config->cookieDomain);
    }

    #[Test]
    public function fromArrayAcceptsCompliantHostPrefixCombination(): void
    {
        $env = Environment::load();

        $config = SessionConfig::fromArray([
            'cookie_host_prefix' => true,
            'cookie_secure' => true,
            'cookie_path' => '/',
            'cookie_domain' => '',
        ], $env);

        self::assertTrue($config->cookieHostPrefix);
        self::assertSame('__Host-PULSAR_SESSION', $config->effectiveCookieName());
    }

    #[Test]
    public function fromArrayRejectsHostPrefixWithoutSecure(): void
    {
        $env = Environment::load();

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('cookie_host_prefix');

        (void) SessionConfig::fromArray([
            'cookie_host_prefix' => true,
            'cookie_secure' => false,
        ], $env);
    }

    #[Test]
    public function fromArrayRejectsHostPrefixWithNonRootPath(): void
    {
        $env = Environment::load();

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('cookie_host_prefix');

        (void) SessionConfig::fromArray([
            'cookie_host_prefix' => true,
            'cookie_secure' => true,
            'cookie_path' => '/admin',
        ], $env);
    }

    #[Test]
    public function fromArrayRejectsHostPrefixWithDomain(): void
    {
        $env = Environment::load();

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('cookie_host_prefix');

        (void) SessionConfig::fromArray([
            'cookie_host_prefix' => true,
            'cookie_secure' => true,
            'cookie_path' => '/',
            'cookie_domain' => 'example.com',
        ], $env);
    }

    #[Test]
    public function readonlyProperties(): void
    {
        $config = new SessionConfig(
            cookieName: 'READONLY',
            lifetime: 100,
            cookieHttpOnly: false,
            cookieSecure: false,
            cookieSameSite: 'None',
            regenerateOnPrivilegeChange: false,
        );

        self::assertSame('READONLY', $config->cookieName);
        self::assertSame(100, $config->lifetime);
        self::assertFalse($config->cookieHttpOnly);
        self::assertFalse($config->cookieSecure);
        self::assertSame('None', $config->cookieSameSite);
        self::assertFalse($config->regenerateOnPrivilegeChange);
    }
}
