<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\SocialSso\Domain\IdTokenClaims;

final class IdTokenClaimsTest extends TestCase
{
    #[Test]
    public function constructsWithRequiredFields(): void
    {
        $claims = new IdTokenClaims(
            sub: 'user-123',
            iss: 'https://issuer.example.com',
            aud: 'client-id',
            exp: 1700000000,
            iat: 1699999000,
        );

        self::assertSame('user-123', $claims->sub);
        self::assertSame('https://issuer.example.com', $claims->iss);
        self::assertSame('client-id', $claims->aud);
        self::assertSame(1700000000, $claims->exp);
        self::assertSame(1699999000, $claims->iat);
        self::assertNull($claims->nonce);
        self::assertNull($claims->azp);
        self::assertSame([], $claims->claims);
    }

    #[Test]
    public function constructsWithOptionalFields(): void
    {
        $claims = new IdTokenClaims(
            sub: 'u1',
            iss: 'https://iss.local',
            aud: ['client-1', 'client-2'],
            exp: 1700000000,
            iat: 1699999000,
            nonce: 'nonce-abc',
            azp: 'client-1',
            claims: ['email' => 'user@example.com'],
        );

        self::assertSame(['client-1', 'client-2'], $claims->aud);
        self::assertSame('nonce-abc', $claims->nonce);
        self::assertSame('client-1', $claims->azp);
        self::assertSame(['email' => 'user@example.com'], $claims->claims);
    }
}
