<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit\Social\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\Social\Domain\IdTokenVerificationContext;

#[CoversClass(IdTokenVerificationContext::class)]
final class IdTokenVerificationContextTest extends TestCase
{
    #[Test]
    public function constructionWithRequiredFields(): void
    {
        $context = new IdTokenVerificationContext(
            clientId: 'my-client-id',
            issuer: 'https://accounts.google.com',
        );

        self::assertSame('my-client-id', $context->clientId);
        self::assertSame('https://accounts.google.com', $context->issuer);
        self::assertNull($context->nonce);
        self::assertSame(120, $context->maxClockSkewSeconds);
    }

    #[Test]
    public function constructionWithAllFields(): void
    {
        $context = new IdTokenVerificationContext(
            clientId: 'client-123',
            issuer: 'https://auth.example.com',
            nonce: 'nonce-abc',
            maxClockSkewSeconds: 60,
        );

        self::assertSame('client-123', $context->clientId);
        self::assertSame('https://auth.example.com', $context->issuer);
        self::assertSame('nonce-abc', $context->nonce);
        self::assertSame(60, $context->maxClockSkewSeconds);
    }
}
