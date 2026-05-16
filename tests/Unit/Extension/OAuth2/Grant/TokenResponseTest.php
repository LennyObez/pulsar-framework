<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\OAuth2\Grant;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\OAuth2\Grant\TokenResponse;

#[CoversClass(TokenResponse::class)]
final class TokenResponseTest extends TestCase
{
    #[Test]
    public function constructionPreservesAllFields(): void
    {
        $response = new TokenResponse(
            accessToken: 'at-value',
            tokenType: 'Bearer',
            expiresIn: 3600,
            scopes: ['openid', 'profile'],
            refreshToken: 'rt-value',
            extraParams: ['id_token' => 'jwt-string'],
        );

        self::assertSame('at-value', $response->accessToken);
        self::assertSame('Bearer', $response->tokenType);
        self::assertSame(3600, $response->expiresIn);
        self::assertSame(['openid', 'profile'], $response->scopes);
        self::assertSame('rt-value', $response->refreshToken);
        self::assertSame(['id_token' => 'jwt-string'], $response->extraParams);
    }

    #[Test]
    public function toArrayMatchesRfc6749Section51(): void
    {
        $response = new TokenResponse(
            accessToken: 'at-value',
            tokenType: 'Bearer',
            expiresIn: 3600,
            scopes: ['openid', 'profile'],
            refreshToken: 'rt-value',
        );

        $array = $response->toArray();

        self::assertSame('at-value', $array['access_token']);
        self::assertSame('Bearer', $array['token_type']);
        self::assertSame(3600, $array['expires_in']);
        self::assertSame('rt-value', $array['refresh_token']);
        self::assertSame('openid profile', $array['scope']);
    }

    #[Test]
    public function toArrayOmitsRefreshTokenWhenNull(): void
    {
        $response = new TokenResponse(
            accessToken: 'at-value',
            tokenType: 'Bearer',
            expiresIn: 3600,
        );

        $array = $response->toArray();

        self::assertArrayNotHasKey('refresh_token', $array);
    }

    #[Test]
    public function toArrayOmitsScopeWhenEmpty(): void
    {
        $response = new TokenResponse(
            accessToken: 'at-value',
            tokenType: 'Bearer',
            expiresIn: 3600,
            scopes: [],
        );

        $array = $response->toArray();

        self::assertArrayNotHasKey('scope', $array);
    }

    #[Test]
    public function toArrayIncludesExtraParams(): void
    {
        $response = new TokenResponse(
            accessToken: 'at-value',
            tokenType: 'Bearer',
            expiresIn: 3600,
            extraParams: ['id_token' => 'jwt-string'],
        );

        $array = $response->toArray();

        self::assertSame('jwt-string', $array['id_token']);
    }

    #[Test]
    public function defaultsAreApplied(): void
    {
        $response = new TokenResponse(
            accessToken: 'at-value',
            tokenType: 'Bearer',
            expiresIn: 3600,
        );

        self::assertSame([], $response->scopes);
        self::assertNull($response->refreshToken);
        self::assertSame([], $response->extraParams);
    }
}
