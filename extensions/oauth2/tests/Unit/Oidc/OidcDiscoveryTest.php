<?php

declare(strict_types=1);

namespace Pulsar\Extension\OAuth2\Tests\Unit\Oidc;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OAuth2\Oidc\OidcConfig;
use Pulsar\Extension\OAuth2\Oidc\OidcDiscovery;

final class OidcDiscoveryTest extends TestCase
{
    #[Test]
    public function configurationDocumentIncludesIssuer(): void
    {
        $config = new OidcConfig(issuer: 'https://auth.example.com');
        $discovery = new OidcDiscovery($config);

        $doc = $discovery->configurationDocument();

        self::assertSame('https://auth.example.com', $doc['issuer']);
    }

    #[Test]
    public function configurationDocumentBuildsEndpointsFromIssuer(): void
    {
        $config = new OidcConfig(issuer: 'https://id.test');
        $discovery = new OidcDiscovery($config);

        $doc = $discovery->configurationDocument();

        self::assertSame('https://id.test/oauth/authorize', $doc['authorization_endpoint']);
        self::assertSame('https://id.test/oauth/token', $doc['token_endpoint']);
        self::assertSame('https://id.test/oauth/userinfo', $doc['userinfo_endpoint']);
        self::assertSame('https://id.test/.well-known/jwks.json', $doc['jwks_uri']);
        self::assertSame('https://id.test/oauth/introspect', $doc['introspection_endpoint']);
        self::assertSame('https://id.test/oauth/revoke', $doc['revocation_endpoint']);
        self::assertSame('https://id.test/docs/oauth2-oidc', $doc['service_documentation']);
    }

    #[Test]
    public function configurationDocumentIncludesSupportedScopes(): void
    {
        $config = new OidcConfig(issuer: 'https://auth.test');
        $discovery = new OidcDiscovery($config);

        $doc = $discovery->configurationDocument();

        self::assertSame(['openid', 'profile', 'email', 'address', 'phone'], $doc['scopes_supported']);
    }

    #[Test]
    public function configurationDocumentIncludesGrantTypes(): void
    {
        $config = new OidcConfig(issuer: 'https://auth.test');
        $discovery = new OidcDiscovery($config);

        $doc = $discovery->configurationDocument();

        self::assertSame(
            ['authorization_code', 'client_credentials', 'refresh_token'],
            $doc['grant_types_supported'],
        );
    }

    #[Test]
    public function configurationDocumentPublicSubjectsOnly(): void
    {
        $config = new OidcConfig(issuer: 'https://auth.test', pairwiseSubjects: false);
        $discovery = new OidcDiscovery($config);

        $doc = $discovery->configurationDocument();

        self::assertSame(['public'], $doc['subject_types_supported']);
    }

    #[Test]
    public function configurationDocumentPairwiseSubjects(): void
    {
        $config = new OidcConfig(issuer: 'https://auth.test', pairwiseSubjects: true);
        $discovery = new OidcDiscovery($config);

        $doc = $discovery->configurationDocument();

        self::assertSame(['public', 'pairwise'], $doc['subject_types_supported']);
    }

    #[Test]
    public function configurationDocumentReflectsSigningAlgorithms(): void
    {
        $config = new OidcConfig(issuer: 'https://auth.test', signingAlgorithms: ['ES256']);
        $discovery = new OidcDiscovery($config);

        $doc = $discovery->configurationDocument();

        self::assertSame(['ES256'], $doc['id_token_signing_alg_values_supported']);
    }

    #[Test]
    public function configurationDocumentIncludesResponseTypes(): void
    {
        $config = new OidcConfig(issuer: 'https://auth.test');
        $discovery = new OidcDiscovery($config);

        $doc = $discovery->configurationDocument();

        self::assertSame(['code'], $doc['response_types_supported']);
        self::assertSame(['query'], $doc['response_modes_supported']);
    }

    #[Test]
    public function configurationDocumentIncludesTokenEndpointAuth(): void
    {
        $config = new OidcConfig(issuer: 'https://auth.test');
        $discovery = new OidcDiscovery($config);

        $doc = $discovery->configurationDocument();

        self::assertSame(
            ['client_secret_basic', 'client_secret_post'],
            $doc['token_endpoint_auth_methods_supported'],
        );
    }

    #[Test]
    public function configurationDocumentIncludesPkceSupport(): void
    {
        $config = new OidcConfig(issuer: 'https://auth.test');
        $discovery = new OidcDiscovery($config);

        $doc = $discovery->configurationDocument();

        self::assertSame(['S256'], $doc['code_challenge_methods_supported']);
    }

    #[Test]
    public function configurationDocumentIncludesClaimsSupported(): void
    {
        $config = new OidcConfig(issuer: 'https://auth.test');
        $discovery = new OidcDiscovery($config);

        $doc = $discovery->configurationDocument();

        /** @var list<string> $claimsSupported */
        $claimsSupported = $doc['claims_supported'];
        self::assertContains('sub', $claimsSupported);
        self::assertContains('email', $claimsSupported);
        self::assertContains('name', $claimsSupported);
        self::assertContains('phone_number', $claimsSupported);
        self::assertContains('address', $claimsSupported);
    }
}
