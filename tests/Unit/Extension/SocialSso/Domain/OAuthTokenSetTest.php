<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\SocialSso\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\SocialSso\Domain\OAuthTokenSet;

#[CoversClass(OAuthTokenSet::class)]
final class OAuthTokenSetTest extends TestCase
{
    #[Test]
    public function constructorSetsAllFields(): void
    {
        $token = new OAuthTokenSet(
            accessToken: 'access-123',
            tokenType: 'Bearer',
            expiresIn: 3600,
            refreshToken: 'refresh-456',
            idToken: 'id.token.jwt',
            unverifiedClaims: ['sub' => 'user-1'],
        );

        self::assertSame('access-123', $token->accessToken);
        self::assertSame('Bearer', $token->tokenType);
        self::assertSame(3600, $token->expiresIn);
        self::assertSame('refresh-456', $token->refreshToken);
        self::assertSame('id.token.jwt', $token->idToken);
        self::assertSame(['sub' => 'user-1'], $token->unverifiedClaims);
    }

    #[Test]
    public function debugInfoRedactsAllTokenMaterial(): void
    {
        $token = new OAuthTokenSet(
            accessToken: 'real-access-token',
            refreshToken: 'real-refresh-token',
            idToken: 'real-id-token',
            unverifiedClaims: ['sub' => 'user-1'],
        );

        $debug = $token->__debugInfo();

        self::assertSame('[REDACTED]', $debug['accessToken']);
        self::assertSame('[REDACTED]', $debug['refreshToken']);
        self::assertSame('[REDACTED]', $debug['idToken']);
        self::assertSame('[REDACTED]', $debug['unverifiedClaims']);
    }

    #[Test]
    public function debugInfoShowsNullForMissingTokens(): void
    {
        $token = new OAuthTokenSet(accessToken: 'access-123');

        $debug = $token->__debugInfo();

        self::assertNull($debug['refreshToken']);
        self::assertNull($debug['idToken']);
        self::assertNull($debug['unverifiedClaims']);
    }
}
