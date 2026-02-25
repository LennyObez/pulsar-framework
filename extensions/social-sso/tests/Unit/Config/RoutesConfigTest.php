<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\SocialSso\Config\RoutesConfig;

final class RoutesConfigTest extends TestCase
{
    #[Test]
    public function fromArrayUsesProvidedPaths(): void
    {
        $config = RoutesConfig::fromArray([
            'login_path' => '/auth/{provider}/start',
            'callback_path' => '/auth/{provider}/return',
        ]);

        self::assertSame('/auth/{provider}/start', $config->loginPath);
        self::assertSame('/auth/{provider}/return', $config->callbackPath);
    }

    #[Test]
    public function fromArrayUsesDefaultPaths(): void
    {
        $config = RoutesConfig::fromArray([]);

        self::assertSame('/sso/{provider}/login', $config->loginPath);
        self::assertSame('/sso/{provider}/callback', $config->callbackPath);
    }

    #[Test]
    public function fromArrayIgnoresNonStringValues(): void
    {
        $config = RoutesConfig::fromArray([
            'login_path' => 123,
            'callback_path' => null,
        ]);

        self::assertSame('/sso/{provider}/login', $config->loginPath);
        self::assertSame('/sso/{provider}/callback', $config->callbackPath);
    }
}
