<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\SocialSso\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\SocialSso\Config\ProviderConfig;
use Pulsar\Extension\SocialSso\Config\RoutesConfig;
use Pulsar\Extension\SocialSso\Config\SocialSsoConfig;

#[CoversClass(SocialSsoConfig::class)]
final class SocialSsoConfigTest extends TestCase
{
    #[Test]
    public function fromArrayCreatesConfigWithDefaults(): void
    {
        $config = SocialSsoConfig::fromArray([]);

        self::assertFalse($config->enabled);
        self::assertSame('', $config->defaultProvider);
        self::assertTrue($config->requirePkce);
        self::assertTrue($config->requireNonce);
        self::assertSame(300, $config->stateTtlSeconds);
        self::assertSame([], $config->providers);
        self::assertInstanceOf(RoutesConfig::class, $config->routes);
    }

    #[Test]
    public function fromArrayCreatesConfigWithCustomValues(): void
    {
        $config = SocialSsoConfig::fromArray([
            'enabled' => true,
            'default_provider' => 'google',
            'require_pkce' => false,
            'require_nonce' => false,
            'state_ttl_seconds' => 600,
            'routes' => [
                'login_path' => '/auth/{provider}/login',
                'callback_path' => '/auth/{provider}/callback',
            ],
            'providers' => [
                'google' => [
                    'type' => 'oidc',
                    'client_id' => 'test-client-id',
                    'client_secret' => 'test-secret',
                    'authorization_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
                    'token_url' => 'https://oauth2.googleapis.com/token',
                    'jwks_uri' => 'https://www.googleapis.com/oauth2/v3/certs',
                    'issuer' => 'https://accounts.google.com',
                    'scopes' => ['openid', 'email'],
                ],
            ],
        ]);

        self::assertTrue($config->enabled);
        self::assertSame('google', $config->defaultProvider);
        self::assertFalse($config->requirePkce);
        self::assertFalse($config->requireNonce);
        self::assertSame(600, $config->stateTtlSeconds);
        self::assertSame('/auth/{provider}/login', $config->routes->loginPath);
        self::assertCount(1, $config->providers);
        self::assertArrayHasKey('google', $config->providers);
        self::assertInstanceOf(ProviderConfig::class, $config->providers['google']);
    }
}
