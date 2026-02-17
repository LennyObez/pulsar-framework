<?php

declare(strict_types=1);

namespace Pulsar\Extension\OAuth2\Tests\Unit\Oidc;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OAuth2\Oidc\OidcConfig;

final class OidcConfigTest extends TestCase
{
    #[Test]
    public function constructionWithDefaults(): void
    {
        $config = new OidcConfig(issuer: 'https://auth.example.com');

        self::assertSame('https://auth.example.com', $config->issuer);
        self::assertSame(['RS256', 'ES256'], $config->signingAlgorithms);
        self::assertFalse($config->pairwiseSubjects);
        self::assertSame('oauth_sign', $config->signingKeyId);
    }

    #[Test]
    public function constructionWithCustomValues(): void
    {
        $config = new OidcConfig(
            issuer: 'https://id.test',
            signingAlgorithms: ['ES384'],
            pairwiseSubjects: true,
            signingKeyId: 'custom_key',
        );

        self::assertSame('https://id.test', $config->issuer);
        self::assertSame(['ES384'], $config->signingAlgorithms);
        self::assertTrue($config->pairwiseSubjects);
        self::assertSame('custom_key', $config->signingKeyId);
    }

    #[Test]
    public function fromArrayWithAllKeys(): void
    {
        $config = OidcConfig::fromArray([
            'issuer' => 'https://oidc.test',
            'signing_algorithms' => ['RS256'],
            'pairwise_subjects' => true,
            'signing_key_id' => 'test_key',
        ]);

        self::assertSame('https://oidc.test', $config->issuer);
        self::assertSame(['RS256'], $config->signingAlgorithms);
        self::assertTrue($config->pairwiseSubjects);
        self::assertSame('test_key', $config->signingKeyId);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $config = OidcConfig::fromArray([]);

        self::assertSame('', $config->issuer);
        self::assertSame(['RS256', 'ES256'], $config->signingAlgorithms);
        self::assertFalse($config->pairwiseSubjects);
        self::assertSame('oauth_sign', $config->signingKeyId);
    }

    #[Test]
    public function fromArrayIgnoresNonStringIssuer(): void
    {
        $config = OidcConfig::fromArray(['issuer' => 42]);

        self::assertSame('', $config->issuer);
    }

    #[Test]
    public function fromArrayIgnoresNonArraySigningAlgorithms(): void
    {
        $config = OidcConfig::fromArray(['signing_algorithms' => 'RS256']);

        self::assertSame(['RS256', 'ES256'], $config->signingAlgorithms);
    }

    #[Test]
    public function fromArrayIgnoresNonStringSigningKeyId(): void
    {
        $config = OidcConfig::fromArray(['signing_key_id' => 123]);

        self::assertSame('oauth_sign', $config->signingKeyId);
    }

    #[Test]
    public function fromArrayCastsPairwiseSubjectsToBoolean(): void
    {
        $config = OidcConfig::fromArray(['pairwise_subjects' => 1]);

        self::assertTrue($config->pairwiseSubjects);
    }

    #[Test]
    public function fromArrayReindexesSigningAlgorithms(): void
    {
        $config = OidcConfig::fromArray([
            'signing_algorithms' => [5 => 'ES256', 10 => 'RS384'],
        ]);

        self::assertSame(['ES256', 'RS384'], $config->signingAlgorithms);
    }
}
