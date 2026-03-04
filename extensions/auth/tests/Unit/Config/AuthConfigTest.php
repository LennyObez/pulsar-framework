<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\Config\AuthConfig;
use Pulsar\Extension\Auth\OAuth2\Config\OAuth2Config;
use Pulsar\Extension\Auth\Social\Config\SocialSsoConfig;
use Pulsar\Extension\Auth\WebAuthn\Config\WebAuthnConfig;

#[CoversClass(AuthConfig::class)]
final class AuthConfigTest extends TestCase
{
    #[Test]
    public function from_array_creates_config_with_defaults(): void
    {
        $config = AuthConfig::fromArray([]);

        self::assertInstanceOf(SocialSsoConfig::class, $config->social);
        self::assertInstanceOf(OAuth2Config::class, $config->oauth2);
        self::assertInstanceOf(WebAuthnConfig::class, $config->webauthn);
    }

    #[Test]
    public function from_array_passes_social_data_to_social_config(): void
    {
        $config = AuthConfig::fromArray([
            'social' => [
                'enabled' => true,
                'default_provider' => 'github',
                'require_pkce' => false,
            ],
        ]);

        self::assertTrue($config->social->enabled);
        self::assertSame('github', $config->social->defaultProvider);
        self::assertFalse($config->social->requirePkce);
    }

    #[Test]
    public function from_array_passes_oauth2_data_to_oauth2_config(): void
    {
        $config = AuthConfig::fromArray([
            'oauth2' => [
                'issuer' => 'https://auth.example.com',
                'access_token_ttl' => 1800,
                'token_format' => 'jwt',
            ],
        ]);

        self::assertSame('https://auth.example.com', $config->oauth2->issuer);
        self::assertSame(1800, $config->oauth2->accessTokenTtl);
        self::assertSame('jwt', $config->oauth2->tokenFormat);
    }

    #[Test]
    public function from_array_passes_webauthn_data_to_webauthn_config(): void
    {
        $config = AuthConfig::fromArray([
            'webauthn' => [
                'rp_name' => 'Test App',
                'rp_id' => 'example.com',
                'origin' => 'https://example.com',
                'timeout' => 30000,
            ],
        ]);

        self::assertSame('Test App', $config->webauthn->rpName);
        self::assertSame('example.com', $config->webauthn->rpId);
        self::assertSame('https://example.com', $config->webauthn->origin);
        self::assertSame(30000, $config->webauthn->timeout);
    }

    #[Test]
    public function constructor_injection_works(): void
    {
        $social = SocialSsoConfig::fromArray([]);
        $oauth2 = OAuth2Config::fromArray([]);
        $webauthn = WebAuthnConfig::fromArray(['rp_name' => 'X', 'rp_id' => 'y', 'origin' => 'z']);

        $config = new AuthConfig($social, $oauth2, $webauthn);

        self::assertSame($social, $config->social);
        self::assertSame($oauth2, $config->oauth2);
        self::assertSame($webauthn, $config->webauthn);
    }
}
