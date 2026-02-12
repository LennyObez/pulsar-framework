<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\SocialSso\Config\ProviderConfig;
use Pulsar\Extension\SocialSso\Exception\SsoException;

final class ProviderConfigTest extends TestCase
{
    #[Test]
    public function fromArrayCreatesConfigWithAllFields(): void
    {
        $config = ProviderConfig::fromArray('google', [
            'type' => 'oidc',
            'client_id' => 'my-client',
            'client_secret' => 'my-secret',
            'authorization_url' => 'https://accounts.google.com/o/oauth2/auth',
            'token_url' => 'https://oauth2.googleapis.com/token',
            'jwks_uri' => 'https://www.googleapis.com/oauth2/v3/certs',
            'issuer' => 'https://accounts.google.com',
            'scopes' => ['openid', 'email', 'profile'],
            'redirect_uri' => 'https://app.local/sso/google/callback',
            'allow_unverified_id_token' => false,
            'max_clock_skew_seconds' => 60,
        ]);

        self::assertSame('google', $config->name);
        self::assertSame('oidc', $config->type);
        self::assertSame('my-client', $config->clientId);
        self::assertSame('my-secret', $config->clientSecret);
        self::assertSame('https://accounts.google.com/o/oauth2/auth', $config->authorizationUrl);
        self::assertSame('https://oauth2.googleapis.com/token', $config->tokenUrl);
        self::assertSame('https://www.googleapis.com/oauth2/v3/certs', $config->jwksUri);
        self::assertSame('https://accounts.google.com', $config->issuer);
        self::assertSame(['openid', 'email', 'profile'], $config->scopes);
        self::assertSame('https://app.local/sso/google/callback', $config->redirectUri);
        self::assertFalse($config->allowUnverifiedIdToken);
        self::assertSame(60, $config->maxClockSkewSeconds);
    }

    #[Test]
    public function fromArrayUsesDefaults(): void
    {
        $config = ProviderConfig::fromArray('github', []);

        self::assertSame('github', $config->name);
        self::assertSame('oidc', $config->type);
        self::assertSame('', $config->clientId);
        self::assertSame('', $config->clientSecret);
        self::assertNull($config->jwksUri);
        self::assertNull($config->issuer);
        self::assertSame([], $config->scopes);
        self::assertNull($config->redirectUri);
        self::assertFalse($config->allowUnverifiedIdToken);
        self::assertSame(120, $config->maxClockSkewSeconds);
    }

    #[Test]
    public function fromArrayRejectsNonHttpsJwksUri(): void
    {
        $this->expectException(SsoException::class);

        (void) ProviderConfig::fromArray('bad', [
            'jwks_uri' => 'http://insecure.example.com/jwks',
        ]);
    }

    #[Test]
    public function fromArrayAcceptsHttpsJwksUri(): void
    {
        $config = ProviderConfig::fromArray('ok', [
            'jwks_uri' => 'https://secure.example.com/jwks',
        ]);

        self::assertSame('https://secure.example.com/jwks', $config->jwksUri);
    }

    #[Test]
    public function fromArrayIgnoresNonStringJwksUri(): void
    {
        $config = ProviderConfig::fromArray('x', ['jwks_uri' => 42]);

        self::assertNull($config->jwksUri);
    }

    #[Test]
    public function debugInfoRedactsClientSecret(): void
    {
        $config = ProviderConfig::fromArray('debug', [
            'client_id' => 'id',
            'client_secret' => 'super-secret-value',
        ]);

        $debug = $config->__debugInfo();

        self::assertSame('[REDACTED]', $debug['clientSecret']);
        self::assertSame('id', $debug['clientId']);
    }
}
