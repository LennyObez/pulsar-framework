<?php

declare(strict_types=1);

namespace Pulsar\Extension\OAuth2\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OAuth2\Config\OAuth2Config;

final class OAuth2ConfigTest extends TestCase
{
    #[Test]
    public function construction_with_defaults(): void
    {
        $config = new OAuth2Config();

        self::assertSame('', $config->issuer);
        self::assertSame(900, $config->accessTokenTtl);
        self::assertSame(2_592_000, $config->refreshTokenTtl);
        self::assertSame(600, $config->authorizationCodeTtl);
        self::assertFalse($config->dynamicRegistration);
        self::assertSame(['RS256', 'ES256'], $config->signingAlgorithms);
        self::assertFalse($config->pairwiseSubjects);
        self::assertSame('oauth_sign', $config->signingKeyId);
        self::assertSame('reference', $config->tokenFormat);
    }

    #[Test]
    public function construction_with_custom_values(): void
    {
        $config = new OAuth2Config(
            issuer: 'https://auth.example.com',
            accessTokenTtl: 1800,
            refreshTokenTtl: 86400,
            authorizationCodeTtl: 300,
            dynamicRegistration: true,
            signingAlgorithms: ['ES256'],
            pairwiseSubjects: true,
            signingKeyId: 'custom_key',
            tokenFormat: 'jwt',
        );

        self::assertSame('https://auth.example.com', $config->issuer);
        self::assertSame(1800, $config->accessTokenTtl);
        self::assertSame(86400, $config->refreshTokenTtl);
        self::assertSame(300, $config->authorizationCodeTtl);
        self::assertTrue($config->dynamicRegistration);
        self::assertSame(['ES256'], $config->signingAlgorithms);
        self::assertTrue($config->pairwiseSubjects);
        self::assertSame('custom_key', $config->signingKeyId);
        self::assertSame('jwt', $config->tokenFormat);
    }

    #[Test]
    public function from_array_with_all_keys(): void
    {
        $config = OAuth2Config::fromArray([
            'issuer' => 'https://auth.test',
            'access_token_ttl' => 3600,
            'refresh_token_ttl' => 604800,
            'authorization_code_ttl' => 120,
            'dynamic_registration' => true,
            'signing_algorithms' => ['RS256'],
            'pairwise_subjects' => true,
            'signing_key_id' => 'my_key',
            'token_format' => 'jwt',
        ]);

        self::assertSame('https://auth.test', $config->issuer);
        self::assertSame(3600, $config->accessTokenTtl);
        self::assertSame(604800, $config->refreshTokenTtl);
        self::assertSame(120, $config->authorizationCodeTtl);
        self::assertTrue($config->dynamicRegistration);
        self::assertSame(['RS256'], $config->signingAlgorithms);
        self::assertTrue($config->pairwiseSubjects);
        self::assertSame('my_key', $config->signingKeyId);
        self::assertSame('jwt', $config->tokenFormat);
    }

    #[Test]
    public function from_array_with_empty_array_uses_defaults(): void
    {
        $config = OAuth2Config::fromArray([]);

        self::assertSame('', $config->issuer);
        self::assertSame(900, $config->accessTokenTtl);
        self::assertFalse($config->dynamicRegistration);
        self::assertSame('reference', $config->tokenFormat);
    }
}
