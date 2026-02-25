<?php

declare(strict_types=1);

namespace Pulsar\Extension\OAuth2\Tests\Unit\Grant;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OAuth2\Grant\TokenResponse;

final class TokenResponseTest extends TestCase
{
    #[Test]
    public function to_array_minimal_response(): void
    {
        $response = new TokenResponse(
            accessToken: 'abc123',
            tokenType: 'Bearer',
            expiresIn: 3600,
        );

        $array = $response->toArray();

        self::assertSame('abc123', $array['access_token']);
        self::assertSame('Bearer', $array['token_type']);
        self::assertSame(3600, $array['expires_in']);
        self::assertArrayNotHasKey('refresh_token', $array);
        self::assertArrayNotHasKey('scope', $array);
    }

    #[Test]
    public function to_array_includes_refresh_token(): void
    {
        $response = new TokenResponse(
            accessToken: 'abc',
            tokenType: 'Bearer',
            expiresIn: 3600,
            refreshToken: 'refresh-xyz',
        );

        $array = $response->toArray();

        self::assertSame('refresh-xyz', $array['refresh_token']);
    }

    #[Test]
    public function to_array_includes_scopes_as_space_separated(): void
    {
        $response = new TokenResponse(
            accessToken: 'abc',
            tokenType: 'Bearer',
            expiresIn: 3600,
            scopes: ['read', 'write', 'admin'],
        );

        $array = $response->toArray();

        self::assertSame('read write admin', $array['scope']);
    }

    #[Test]
    public function to_array_merges_extra_params(): void
    {
        $response = new TokenResponse(
            accessToken: 'abc',
            tokenType: 'Bearer',
            expiresIn: 3600,
            extraParams: ['id_token' => 'jwt-value'],
        );

        $array = $response->toArray();

        self::assertSame('jwt-value', $array['id_token']);
    }

    #[Test]
    public function construction_with_all_fields(): void
    {
        $response = new TokenResponse(
            accessToken: 'tok',
            tokenType: 'Bearer',
            expiresIn: 1800,
            scopes: ['openid'],
            refreshToken: 'ref',
            extraParams: ['custom' => 'val'],
        );

        self::assertSame('tok', $response->accessToken);
        self::assertSame('Bearer', $response->tokenType);
        self::assertSame(1800, $response->expiresIn);
        self::assertSame(['openid'], $response->scopes);
        self::assertSame('ref', $response->refreshToken);
        self::assertSame(['custom' => 'val'], $response->extraParams);
    }
}
