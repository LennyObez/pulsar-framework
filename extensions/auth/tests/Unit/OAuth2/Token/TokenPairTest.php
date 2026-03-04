<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit\OAuth2\Token;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\OAuth2\Token\AccessToken;
use Pulsar\Extension\Auth\OAuth2\Token\RefreshToken;
use Pulsar\Extension\Auth\OAuth2\Token\TokenPair;

final class TokenPairTest extends TestCase
{
    #[Test]
    public function constructorSetsProperties(): void
    {
        $accessToken = new AccessToken(
            id: 'at-1',
            clientId: 'client-1',
            subjectId: 'user-1',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
        );

        $refreshToken = new RefreshToken(
            id: 'rt-1',
            clientId: 'client-1',
            subjectId: 'user-1',
            sessionId: 'sess-1',
            familyId: 'fam-1',
            scopes: ['openid'],
            expiresAt: new DateTimeImmutable('+30 days'),
            issuedAt: new DateTimeImmutable(),
        );

        $pair = new TokenPair($accessToken, $refreshToken);

        self::assertSame($accessToken, $pair->accessToken);
        self::assertSame($refreshToken, $pair->refreshToken);
    }
}
