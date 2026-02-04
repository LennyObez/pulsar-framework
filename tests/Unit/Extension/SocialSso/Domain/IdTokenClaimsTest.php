<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\SocialSso\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\SocialSso\Domain\IdTokenClaims;

#[CoversClass(IdTokenClaims::class)]
final class IdTokenClaimsTest extends TestCase
{
    #[Test]
    public function constructorSetsStandardClaims(): void
    {
        $claims = new IdTokenClaims(
            sub: 'user-123',
            iss: 'https://accounts.google.com',
            aud: 'client-id',
            exp: 1700000000,
            iat: 1699996400,
            nonce: 'nonce-value',
            azp: 'client-id',
            claims: ['email' => 'user@example.com'],
        );

        self::assertSame('user-123', $claims->sub);
        self::assertSame('https://accounts.google.com', $claims->iss);
        self::assertSame('client-id', $claims->aud);
        self::assertSame(1700000000, $claims->exp);
        self::assertSame(1699996400, $claims->iat);
        self::assertSame('nonce-value', $claims->nonce);
        self::assertSame('client-id', $claims->azp);
        self::assertSame(['email' => 'user@example.com'], $claims->claims);
    }

    #[Test]
    public function audCanBeArray(): void
    {
        $claims = new IdTokenClaims(
            sub: 'user-1',
            iss: 'https://example.com',
            aud: ['client-1', 'client-2'],
            exp: 1700000000,
            iat: 1699996400,
        );

        self::assertSame(['client-1', 'client-2'], $claims->aud);
    }
}
