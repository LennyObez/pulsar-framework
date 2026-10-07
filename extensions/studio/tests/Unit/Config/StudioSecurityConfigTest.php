<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Environment;
use Pulsar\Extension\Studio\Config\StudioSecurityConfig;

final class StudioSecurityConfigTest extends TestCase
{
    #[Test]
    public function defaultValues(): void
    {
        $config = new StudioSecurityConfig();

        self::assertFalse($config->authRequired);
        self::assertNull($config->username);
        self::assertNull($config->password);
        self::assertSame(['127.0.0.1/8', '::1/128'], $config->allowedCidrs);
        self::assertFalse($config->productionConfirm);
    }

    #[Test]
    public function fromArrayReadsAuthRequired(): void
    {
        $env = Environment::load();
        $config = StudioSecurityConfig::fromArray(['auth_required' => true], $env);

        self::assertTrue($config->authRequired);
    }

    #[Test]
    public function fromArrayReadsCredentials(): void
    {
        $env = Environment::load();
        $config = StudioSecurityConfig::fromArray([
            'username' => 'admin',
            'password' => 'secret',
        ], $env);

        self::assertSame('admin', $config->username);
        self::assertSame('secret', $config->password);
    }

    #[Test]
    public function fromArrayReadsAllowedCidrs(): void
    {
        $env = Environment::load();
        $config = StudioSecurityConfig::fromArray([
            'allowed_cidrs' => ['10.0.0.0/8'],
        ], $env);

        self::assertSame(['10.0.0.0/8'], $config->allowedCidrs);
    }
}
