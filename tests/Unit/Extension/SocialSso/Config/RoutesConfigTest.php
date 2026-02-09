<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\SocialSso\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\SocialSso\Config\RoutesConfig;

#[CoversClass(RoutesConfig::class)]
final class RoutesConfigTest extends TestCase
{
    #[Test]
    public function fromArrayCreatesWithDefaults(): void
    {
        $config = RoutesConfig::fromArray([]);

        self::assertSame('/sso/{provider}/login', $config->loginPath);
        self::assertSame('/sso/{provider}/callback', $config->callbackPath);
    }

    #[Test]
    public function fromArrayCreatesWithCustomPaths(): void
    {
        $config = RoutesConfig::fromArray([
            'login_path' => '/auth/login/{provider}',
            'callback_path' => '/auth/callback/{provider}',
        ]);

        self::assertSame('/auth/login/{provider}', $config->loginPath);
        self::assertSame('/auth/callback/{provider}', $config->callbackPath);
    }
}
