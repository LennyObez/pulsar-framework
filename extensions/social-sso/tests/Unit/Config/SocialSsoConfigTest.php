<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\SocialSso\Config\SocialSsoConfig;

final class SocialSsoConfigTest extends TestCase
{
    #[Test]
    public function fromArrayBuildsFullConfig(): void
    {
        $config = SocialSsoConfig::fromArray([
            'enabled' => true,
            'default_provider' => 'google',
            'require_pkce' => true,
            'require_nonce' => false,
            'state_ttl_seconds' => 600,
            'routes' => [
                'login_path' => '/login/{provider}',
                'callback_path' => '/callback/{provider}',
            ],
            'providers' => [
                'google' => [
                    'type' => 'oidc',
                    'client_id' => 'google-id',
                    'client_secret' => 'google-secret',
                    'authorization_url' => 'https://accounts.google.com/o/oauth2/auth',
                    'token_url' => 'https://oauth2.googleapis.com/token',
                ],
            ],
        ]);

        self::assertTrue($config->enabled);
        self::assertSame('google', $config->defaultProvider);
        self::assertTrue($config->requirePkce);
        self::assertFalse($config->requireNonce);
        self::assertSame(600, $config->stateTtlSeconds);
        self::assertSame('/login/{provider}', $config->routes->loginPath);
        self::assertCount(1, $config->providers);
        self::assertArrayHasKey('google', $config->providers);
        self::assertSame('google-id', $config->providers['google']->clientId);
    }

    #[Test]
    public function fromArrayUsesDefaults(): void
    {
        $config = SocialSsoConfig::fromArray([]);

        self::assertFalse($config->enabled);
        self::assertSame('', $config->defaultProvider);
        self::assertTrue($config->requirePkce);
        self::assertTrue($config->requireNonce);
        self::assertSame(300, $config->stateTtlSeconds);
        self::assertSame([], $config->providers);
    }

    #[Test]
    public function fromArrayHandlesMultipleProviders(): void
    {
        $config = SocialSsoConfig::fromArray([
            'providers' => [
                'google' => ['client_id' => 'g-id', 'client_secret' => 'g-secret'],
                'github' => ['client_id' => 'gh-id', 'client_secret' => 'gh-secret'],
            ],
        ]);

        self::assertCount(2, $config->providers);
        self::assertSame('g-id', $config->providers['google']->clientId);
        self::assertSame('gh-id', $config->providers['github']->clientId);
    }
}
