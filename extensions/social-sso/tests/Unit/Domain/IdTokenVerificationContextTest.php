<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\SocialSso\Domain\IdTokenVerificationContext;

final class IdTokenVerificationContextTest extends TestCase
{
    #[Test]
    public function constructsWithDefaults(): void
    {
        $ctx = new IdTokenVerificationContext(
            clientId: 'my-client',
            issuer: 'https://issuer.example.com',
        );

        self::assertSame('my-client', $ctx->clientId);
        self::assertSame('https://issuer.example.com', $ctx->issuer);
        self::assertNull($ctx->nonce);
        self::assertSame(120, $ctx->maxClockSkewSeconds);
    }

    #[Test]
    public function constructsWithAllParams(): void
    {
        $ctx = new IdTokenVerificationContext(
            clientId: 'c',
            issuer: 'https://iss',
            nonce: 'n123',
            maxClockSkewSeconds: 60,
        );

        self::assertSame('n123', $ctx->nonce);
        self::assertSame(60, $ctx->maxClockSkewSeconds);
    }
}
