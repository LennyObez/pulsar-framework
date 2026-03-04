<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\SocialSso;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\SocialSso\Config\ProviderConfig;
use Pulsar\Extension\SocialSso\Config\SocialProviders;

#[CoversClass(SocialProviders::class)]
final class SocialProvidersTest extends TestCase
{
    #[Test]
    public function googleProviderHasCorrectEndpoints(): void
    {
        $config = SocialProviders::google('client-id', 'client-secret');

        self::assertSame('google', $config->name);
        self::assertSame('oidc', $config->type);
        self::assertSame('client-id', $config->clientId);
        self::assertStringContainsString('accounts.google.com', $config->authorizationUrl);
        self::assertStringContainsString('googleapis.com/token', $config->tokenUrl);
        self::assertNotNull($config->jwksUri);
        self::assertStringContainsString('googleapis.com', $config->jwksUri);
        self::assertSame('https://accounts.google.com', $config->issuer);
        self::assertContains('openid', $config->scopes);
        self::assertContains('email', $config->scopes);
    }

    #[Test]
    public function githubProviderHasCorrectEndpoints(): void
    {
        $config = SocialProviders::github('gh-id', 'gh-secret');

        self::assertSame('github', $config->name);
        self::assertSame('oauth2', $config->type);
        self::assertStringContainsString('github.com/login/oauth', $config->authorizationUrl);
        self::assertStringContainsString('github.com/login/oauth/access_token', $config->tokenUrl);
        self::assertNull($config->jwksUri); // GitHub doesn't support OIDC
        self::assertContains('read:user', $config->scopes);
    }

    #[Test]
    public function facebookProviderHasCorrectEndpoints(): void
    {
        $config = SocialProviders::facebook('fb-id', 'fb-secret');

        self::assertSame('facebook', $config->name);
        self::assertStringContainsString('facebook.com', $config->authorizationUrl);
        self::assertStringContainsString('graph.facebook.com', $config->tokenUrl);
        self::assertContains('email', $config->scopes);
    }

    #[Test]
    public function appleProviderHasCorrectEndpoints(): void
    {
        $config = SocialProviders::apple('apple-id', 'apple-secret');

        self::assertSame('apple', $config->name);
        self::assertSame('oidc', $config->type);
        self::assertStringContainsString('appleid.apple.com', $config->authorizationUrl);
        self::assertNotNull($config->jwksUri);
        self::assertStringContainsString('appleid.apple.com/auth/keys', $config->jwksUri);
        self::assertSame('https://appleid.apple.com', $config->issuer);
    }

    #[Test]
    public function microsoftProviderUsesCommonTenantByDefault(): void
    {
        $config = SocialProviders::microsoft('ms-id', 'ms-secret');

        self::assertSame('microsoft', $config->name);
        self::assertSame('oidc', $config->type);
        self::assertStringContainsString('login.microsoftonline.com/common', $config->authorizationUrl);
        self::assertStringContainsString('/common/', $config->tokenUrl);
    }

    #[Test]
    public function microsoftProviderAcceptsCustomTenant(): void
    {
        $config = SocialProviders::microsoft('ms-id', 'ms-secret', tenant: 'my-tenant-id');

        self::assertStringContainsString('my-tenant-id', $config->authorizationUrl);
        self::assertStringContainsString('my-tenant-id', $config->tokenUrl);
    }

    #[Test]
    public function allProvidersAcceptRedirectUri(): void
    {
        $redirect = 'https://myapp.com/callback';

        $google = SocialProviders::google('id', 'secret', $redirect);
        $github = SocialProviders::github('id', 'secret', $redirect);
        $facebook = SocialProviders::facebook('id', 'secret', $redirect);
        $apple = SocialProviders::apple('id', 'secret', $redirect);
        $microsoft = SocialProviders::microsoft('id', 'secret', $redirect);

        self::assertSame($redirect, $google->redirectUri);
        self::assertSame($redirect, $github->redirectUri);
        self::assertSame($redirect, $facebook->redirectUri);
        self::assertSame($redirect, $apple->redirectUri);
        self::assertSame($redirect, $microsoft->redirectUri);
    }

    #[Test]
    public function allProvidersReturnProviderConfigInstances(): void
    {
        self::assertInstanceOf(ProviderConfig::class, SocialProviders::google('id', 's'));
        self::assertInstanceOf(ProviderConfig::class, SocialProviders::github('id', 's'));
        self::assertInstanceOf(ProviderConfig::class, SocialProviders::facebook('id', 's'));
        self::assertInstanceOf(ProviderConfig::class, SocialProviders::apple('id', 's'));
        self::assertInstanceOf(ProviderConfig::class, SocialProviders::microsoft('id', 's'));
    }

    #[Test]
    public function allOidcProvidersHaveJwksUri(): void
    {
        $google = SocialProviders::google('id', 's');
        $apple = SocialProviders::apple('id', 's');
        $microsoft = SocialProviders::microsoft('id', 's');

        self::assertNotNull($google->jwksUri);
        self::assertNotNull($apple->jwksUri);
        self::assertNotNull($microsoft->jwksUri);

        // JWKS URIs must use HTTPS
        self::assertStringStartsWith('https://', $google->jwksUri);
        self::assertStringStartsWith('https://', $apple->jwksUri);
        self::assertStringStartsWith('https://', $microsoft->jwksUri);
    }
}
