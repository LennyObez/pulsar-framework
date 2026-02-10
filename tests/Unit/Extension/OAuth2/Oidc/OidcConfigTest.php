<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\OAuth2\Oidc;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OAuth2\Oidc\OidcConfig;

#[CoversClass(OidcConfig::class)]
final class OidcConfigTest extends TestCase
{
    #[Test]
    public function constructionPreservesAllFields(): void
    {
        $config = new OidcConfig(
            issuer: 'https://auth.example.com',
            signingAlgorithms: ['RS256'],
            pairwiseSubjects: true,
            signingKeyId: 'my_key',
        );

        self::assertSame('https://auth.example.com', $config->issuer);
        self::assertSame(['RS256'], $config->signingAlgorithms);
        self::assertTrue($config->pairwiseSubjects);
        self::assertSame('my_key', $config->signingKeyId);
    }

    #[Test]
    public function defaultValues(): void
    {
        $config = new OidcConfig(issuer: 'https://auth.example.com');

        self::assertSame(['RS256', 'ES256'], $config->signingAlgorithms);
        self::assertFalse($config->pairwiseSubjects);
        self::assertSame('oauth_sign', $config->signingKeyId);
    }

    #[Test]
    public function fromArrayCreatesConfig(): void
    {
        $config = OidcConfig::fromArray([
            'issuer' => 'https://auth.example.com',
            'signing_algorithms' => ['ES256'],
            'pairwise_subjects' => true,
            'signing_key_id' => 'custom_key',
        ]);

        self::assertSame('https://auth.example.com', $config->issuer);
        self::assertSame(['ES256'], $config->signingAlgorithms);
        self::assertTrue($config->pairwiseSubjects);
        self::assertSame('custom_key', $config->signingKeyId);
    }

    #[Test]
    public function fromArrayUsesDefaults(): void
    {
        $config = OidcConfig::fromArray([]);

        self::assertSame('', $config->issuer);
        self::assertSame(['RS256', 'ES256'], $config->signingAlgorithms);
        self::assertFalse($config->pairwiseSubjects);
        self::assertSame('oauth_sign', $config->signingKeyId);
    }
}
