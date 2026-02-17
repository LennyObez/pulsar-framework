<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit\OAuth2\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\OAuth2\Config\OAuth2Config;

final class OAuth2ConfigTest extends TestCase
{
    #[Test]
    public function fromArrayParsesAllFields(): void
    {
        $config = OAuth2Config::fromArray([
            'issuer' => 'https://auth.example.com',
            'access_token_ttl' => 3600,
            'refresh_token_ttl' => 86400,
            'authorization_code_ttl' => 300,
            'dynamic_registration' => true,
            'signing_algorithms' => ['ES256'],
            'pairwise_subjects' => true,
            'signing_key_id' => 'custom-key',
            'token_format' => 'jwt',
        ]);

        self::assertSame('https://auth.example.com', $config->issuer);
        self::assertSame(3600, $config->accessTokenTtl);
        self::assertSame(86400, $config->refreshTokenTtl);
        self::assertSame(300, $config->authorizationCodeTtl);
        self::assertTrue($config->dynamicRegistration);
        self::assertSame(['ES256'], $config->signingAlgorithms);
        self::assertTrue($config->pairwiseSubjects);
        self::assertSame('custom-key', $config->signingKeyId);
        self::assertSame('jwt', $config->tokenFormat);
    }

    #[Test]
    public function fromArrayUsesDefaults(): void
    {
        $config = OAuth2Config::fromArray([]);

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
    public function constructorDefaultsMatchFromArrayDefaults(): void
    {
        $fromConstructor = new OAuth2Config();
        $fromArray = OAuth2Config::fromArray([]);

        self::assertSame($fromConstructor->issuer, $fromArray->issuer);
        self::assertSame($fromConstructor->accessTokenTtl, $fromArray->accessTokenTtl);
        self::assertSame($fromConstructor->refreshTokenTtl, $fromArray->refreshTokenTtl);
        self::assertSame($fromConstructor->authorizationCodeTtl, $fromArray->authorizationCodeTtl);
        self::assertSame($fromConstructor->tokenFormat, $fromArray->tokenFormat);
    }
}
