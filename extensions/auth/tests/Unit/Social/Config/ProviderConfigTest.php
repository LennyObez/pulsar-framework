<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit\Social\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\Social\Config\ProviderConfig;
use Pulsar\Extension\Auth\Social\Exception\SsoException;

#[CoversClass(ProviderConfig::class)]
final class ProviderConfigTest extends TestCase
{
    #[Test]
    public function fromArrayCreatesProviderConfig(): void
    {
        $config = ProviderConfig::fromArray('google', [
            'type' => 'oidc',
            'client_id' => 'test-id',
            'client_secret' => 'test-secret',
            'authorization_url' => 'https://auth.example.com/authorize',
            'token_url' => 'https://auth.example.com/token',
            'jwks_uri' => 'https://auth.example.com/.well-known/jwks.json',
            'issuer' => 'https://auth.example.com',
            'scopes' => ['openid', 'profile'],
            'redirect_uri' => 'https://app.example.com/callback',
        ]);

        self::assertSame('google', $config->name);
        self::assertSame('oidc', $config->type);
        self::assertSame('test-id', $config->clientId);
        self::assertSame('test-secret', $config->clientSecret);
        self::assertSame('https://auth.example.com/authorize', $config->authorizationUrl);
        self::assertSame('https://auth.example.com/token', $config->tokenUrl);
        self::assertSame('https://auth.example.com/.well-known/jwks.json', $config->jwksUri);
        self::assertSame('https://auth.example.com', $config->issuer);
        self::assertSame(['openid', 'profile'], $config->scopes);
        self::assertSame('https://app.example.com/callback', $config->redirectUri);
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
    public function fromArrayAllowsNullJwksUri(): void
    {
        $config = ProviderConfig::fromArray('oauth2-provider', [
            'type' => 'oauth2',
            'client_id' => 'id',
            'client_secret' => 'secret',
        ]);

        self::assertNull($config->jwksUri);
    }

    #[Test]
    public function debugInfoRedactsClientSecret(): void
    {
        $config = ProviderConfig::fromArray('test', [
            'client_id' => 'my-id',
            'client_secret' => 'super-secret-value',
        ]);

        $debug = $config->__debugInfo();

        self::assertSame('[REDACTED]', $debug['clientSecret']);
        self::assertSame('my-id', $debug['clientId']);
    }
}
