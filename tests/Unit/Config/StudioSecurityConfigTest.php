<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Environment;
use Pulsar\Config\StudioSecurityConfig;

#[CoversClass(StudioSecurityConfig::class)]
final class StudioSecurityConfigTest extends TestCase
{
    /** @var list<string> Env vars set during tests, to be cleaned up */
    private array $envVarsToClean = [];

    protected function tearDown(): void
    {
        foreach ($this->envVarsToClean as $key) {
            putenv($key);
        }

        $this->envVarsToClean = [];
    }

    private function setEnv(string $key, string $value): void
    {
        putenv("{$key}={$value}");
        $this->envVarsToClean[] = $key;
    }

    #[Test]
    public function it_has_sensible_defaults(): void
    {
        $config = new StudioSecurityConfig();

        self::assertFalse($config->authRequired);
        self::assertNull($config->username);
        self::assertNull($config->password);
        self::assertSame(['127.0.0.1/8', '::1/128'], $config->allowedCidrs);
        self::assertFalse($config->productionConfirm);
    }

    #[Test]
    public function it_creates_from_array_with_full_data(): void
    {
        $env = Environment::load(null);

        $result = StudioSecurityConfig::fromArray([
            'auth_required' => true,
            'username' => 'admin',
            'password' => 'secret',
            'allowed_cidrs' => ['10.0.0.0/8', '192.168.0.0/16'],
            'production_confirm' => true,
        ], $env);

        self::assertTrue($result->authRequired);
        self::assertSame('admin', $result->username);
        self::assertSame('secret', $result->password);
        self::assertSame(['10.0.0.0/8', '192.168.0.0/16'], $result->allowedCidrs);
        self::assertTrue($result->productionConfirm);
    }

    #[Test]
    public function it_uses_defaults_when_array_is_empty(): void
    {
        $env = Environment::load(null);

        $result = StudioSecurityConfig::fromArray([], $env);

        self::assertFalse($result->authRequired);
        self::assertNull($result->username);
        self::assertNull($result->password);
        self::assertSame(['127.0.0.1/8', '::1/128'], $result->allowedCidrs);
        self::assertFalse($result->productionConfirm);
    }

    #[Test]
    public function env_override_for_username(): void
    {
        $this->setEnv('STUDIO_USERNAME', 'env_admin');

        $env = Environment::load(null);

        $result = StudioSecurityConfig::fromArray([
            'username' => 'array_admin',
        ], $env);

        self::assertSame('env_admin', $result->username);
    }

    #[Test]
    public function env_override_for_password(): void
    {
        $this->setEnv('STUDIO_PASSWORD', 'env_secret');

        $env = Environment::load(null);

        $result = StudioSecurityConfig::fromArray([
            'password' => 'array_secret',
        ], $env);

        self::assertSame('env_secret', $result->password);
    }

    #[Test]
    public function env_override_for_production_confirm_true(): void
    {
        $this->setEnv('STUDIO_PRODUCTION_CONFIRM', 'true');

        $env = Environment::load(null);

        $result = StudioSecurityConfig::fromArray([
            'production_confirm' => false,
        ], $env);

        self::assertTrue($result->productionConfirm);
    }

    #[Test]
    public function env_override_for_production_confirm_false(): void
    {
        $this->setEnv('STUDIO_PRODUCTION_CONFIRM', 'false');

        $env = Environment::load(null);

        $result = StudioSecurityConfig::fromArray([
            'production_confirm' => true,
        ], $env);

        self::assertFalse($result->productionConfirm);
    }

    #[Test]
    public function env_overrides_take_precedence_for_credentials(): void
    {
        $this->setEnv('STUDIO_USERNAME', 'env_user');
        $this->setEnv('STUDIO_PASSWORD', 'env_pass');

        $env = Environment::load(null);

        $result = StudioSecurityConfig::fromArray([
            'username' => 'file_user',
            'password' => 'file_pass',
        ], $env);

        self::assertSame('env_user', $result->username);
        self::assertSame('env_pass', $result->password);
    }
}
