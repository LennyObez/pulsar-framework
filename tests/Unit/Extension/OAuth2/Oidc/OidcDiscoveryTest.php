<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\OAuth2\Oidc;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\OAuth2\Oidc\OidcConfig;
use Pulsar\Extension\Auth\OAuth2\Oidc\OidcDiscovery;

#[CoversClass(OidcDiscovery::class)]
final class OidcDiscoveryTest extends TestCase
{
    #[Test]
    public function discoveryDocumentContainsRequiredFields(): void
    {
        $config = new OidcConfig(issuer: 'https://auth.example.com');
        $discovery = new OidcDiscovery($config);

        $doc = $discovery->configurationDocument();

        // Required per OpenID Connect Discovery 1.0
        self::assertSame('https://auth.example.com', $doc['issuer']);
        self::assertArrayHasKey('authorization_endpoint', $doc);
        self::assertArrayHasKey('token_endpoint', $doc);
        self::assertArrayHasKey('userinfo_endpoint', $doc);
        self::assertArrayHasKey('jwks_uri', $doc);
        self::assertArrayHasKey('scopes_supported', $doc);
        self::assertArrayHasKey('response_types_supported', $doc);
        self::assertArrayHasKey('subject_types_supported', $doc);
        self::assertArrayHasKey('id_token_signing_alg_values_supported', $doc);
        self::assertArrayHasKey('claims_supported', $doc);
    }

    #[Test]
    public function endpointsAreRelativeToIssuer(): void
    {
        $config = new OidcConfig(issuer: 'https://auth.example.com');
        $discovery = new OidcDiscovery($config);

        $doc = $discovery->configurationDocument();

        self::assertSame('https://auth.example.com/oauth/authorize', $doc['authorization_endpoint']);
        self::assertSame('https://auth.example.com/oauth/token', $doc['token_endpoint']);
        self::assertSame('https://auth.example.com/oauth/userinfo', $doc['userinfo_endpoint']);
        self::assertSame('https://auth.example.com/.well-known/jwks.json', $doc['jwks_uri']);
        self::assertSame('https://auth.example.com/oauth/introspect', $doc['introspection_endpoint']);
        self::assertSame('https://auth.example.com/oauth/revoke', $doc['revocation_endpoint']);
    }

    #[Test]
    public function scopesSupportedIncludesOidcStandardScopes(): void
    {
        $config = new OidcConfig(issuer: 'https://auth.example.com');
        $discovery = new OidcDiscovery($config);

        $doc = $discovery->configurationDocument();

        /** @var list<string> $scopes */
        $scopes = $doc['scopes_supported'];
        self::assertContains('openid', $scopes);
        self::assertContains('profile', $scopes);
        self::assertContains('email', $scopes);
        self::assertContains('address', $scopes);
        self::assertContains('phone', $scopes);
    }

    #[Test]
    public function responseTypeSupportedIsCodeOnly(): void
    {
        $config = new OidcConfig(issuer: 'https://auth.example.com');
        $discovery = new OidcDiscovery($config);

        $doc = $discovery->configurationDocument();

        self::assertSame(['code'], $doc['response_types_supported']);
    }

    #[Test]
    public function grantTypesSupportedIncludesAllThreeGrants(): void
    {
        $config = new OidcConfig(issuer: 'https://auth.example.com');
        $discovery = new OidcDiscovery($config);

        $doc = $discovery->configurationDocument();

        /** @var list<string> $grants */
        $grants = $doc['grant_types_supported'];
        self::assertContains('authorization_code', $grants);
        self::assertContains('client_credentials', $grants);
        self::assertContains('refresh_token', $grants);
    }

    #[Test]
    public function onlyS256CodeChallengeMethodSupported(): void
    {
        $config = new OidcConfig(issuer: 'https://auth.example.com');
        $discovery = new OidcDiscovery($config);

        $doc = $discovery->configurationDocument();

        self::assertSame(['S256'], $doc['code_challenge_methods_supported']);
    }

    #[Test]
    public function subjectTypesPublicOnlyByDefault(): void
    {
        $config = new OidcConfig(issuer: 'https://auth.example.com', pairwiseSubjects: false);
        $discovery = new OidcDiscovery($config);

        $doc = $discovery->configurationDocument();

        self::assertSame(['public'], $doc['subject_types_supported']);
    }

    #[Test]
    public function subjectTypesIncludesPairwiseWhenEnabled(): void
    {
        $config = new OidcConfig(issuer: 'https://auth.example.com', pairwiseSubjects: true);
        $discovery = new OidcDiscovery($config);

        $doc = $discovery->configurationDocument();

        /** @var list<string> $subjectTypes */
        $subjectTypes = $doc['subject_types_supported'];
        self::assertContains('public', $subjectTypes);
        self::assertContains('pairwise', $subjectTypes);
    }

    #[Test]
    public function signingAlgorithmsFromConfig(): void
    {
        $config = new OidcConfig(
            issuer: 'https://auth.example.com',
            signingAlgorithms: ['RS256', 'ES256'],
        );
        $discovery = new OidcDiscovery($config);

        $doc = $discovery->configurationDocument();

        self::assertSame(['RS256', 'ES256'], $doc['id_token_signing_alg_values_supported']);
    }

    #[Test]
    public function claimsSupportedIncludesRequiredOidcClaims(): void
    {
        $config = new OidcConfig(issuer: 'https://auth.example.com');
        $discovery = new OidcDiscovery($config);

        $doc = $discovery->configurationDocument();

        /** @var list<string> $claims */
        $claims = $doc['claims_supported'];

        // Required ID token claims
        self::assertContains('sub', $claims);
        self::assertContains('iss', $claims);
        self::assertContains('aud', $claims);
        self::assertContains('exp', $claims);
        self::assertContains('iat', $claims);
        self::assertContains('nonce', $claims);

        // Profile claims
        self::assertContains('name', $claims);
        self::assertContains('email', $claims);
        self::assertContains('email_verified', $claims);

        // Phone claims
        self::assertContains('phone_number', $claims);
    }
}
