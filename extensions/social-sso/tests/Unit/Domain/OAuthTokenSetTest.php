<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\SocialSso\Domain\OAuthTokenSet;

final class OAuthTokenSetTest extends TestCase
{
    #[Test]
    public function constructsWithRequiredAccessToken(): void
    {
        $tokenSet = new OAuthTokenSet(accessToken: 'access-123');

        self::assertSame('access-123', $tokenSet->accessToken);
        self::assertNull($tokenSet->tokenType);
        self::assertNull($tokenSet->expiresIn);
        self::assertNull($tokenSet->refreshToken);
        self::assertNull($tokenSet->idToken);
        self::assertNull($tokenSet->unverifiedClaims);
    }

    #[Test]
    public function constructsWithAllFields(): void
    {
        $tokenSet = new OAuthTokenSet(
            accessToken: 'at',
            tokenType: 'Bearer',
            expiresIn: 3600,
            refreshToken: 'rt',
            idToken: 'id-jwt',
            unverifiedClaims: ['sub' => 'user1'],
        );

        self::assertSame('Bearer', $tokenSet->tokenType);
        self::assertSame(3600, $tokenSet->expiresIn);
        self::assertSame('rt', $tokenSet->refreshToken);
        self::assertSame('id-jwt', $tokenSet->idToken);
        self::assertSame(['sub' => 'user1'], $tokenSet->unverifiedClaims);
    }

    #[Test]
    public function debugInfoRedactsSensitiveFields(): void
    {
        $tokenSet = new OAuthTokenSet(
            accessToken: 'secret-access',
            refreshToken: 'secret-refresh',
            idToken: 'secret-id',
            unverifiedClaims: ['sub' => 'user'],
        );

        $debug = $tokenSet->__debugInfo();

        self::assertSame('[REDACTED]', $debug['accessToken']);
        self::assertSame('[REDACTED]', $debug['refreshToken']);
        self::assertSame('[REDACTED]', $debug['idToken']);
        self::assertSame('[REDACTED]', $debug['unverifiedClaims']);
    }

    #[Test]
    public function debugInfoShowsNullForAbsentOptionalTokens(): void
    {
        $tokenSet = new OAuthTokenSet(accessToken: 'at');

        $debug = $tokenSet->__debugInfo();

        self::assertNull($debug['refreshToken']);
        self::assertNull($debug['idToken']);
        self::assertNull($debug['unverifiedClaims']);
    }
}
