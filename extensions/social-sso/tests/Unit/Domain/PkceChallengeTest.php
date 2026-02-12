<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\SocialSso\Domain\PkceChallenge;

use function hash;
use function rtrim;
use function str_replace;

final class PkceChallengeTest extends TestCase
{
    #[Test]
    public function generateProducesValidVerifier(): void
    {
        $pkce = PkceChallenge::generate();

        self::assertSame('S256', $pkce->method);
        self::assertNotEmpty($pkce->verifier);
        self::assertNotEmpty($pkce->challenge);
    }

    #[Test]
    public function challengeIsS256HashOfVerifier(): void
    {
        $pkce = PkceChallenge::generate();

        $expectedChallenge = rtrim(str_replace(
            ['+', '/'],
            ['-', '_'],
            base64_encode(hash('sha256', $pkce->verifier, true)),
        ), '=');

        self::assertSame($expectedChallenge, $pkce->challenge);
    }

    #[Test]
    public function generateProducesUniqueValues(): void
    {
        $a = PkceChallenge::generate();
        $b = PkceChallenge::generate();

        self::assertNotSame($a->verifier, $b->verifier);
        self::assertNotSame($a->challenge, $b->challenge);
    }

    #[Test]
    public function constructorAcceptsCustomValues(): void
    {
        $pkce = new PkceChallenge('verifier', 'challenge', 'S256');

        self::assertSame('verifier', $pkce->verifier);
        self::assertSame('challenge', $pkce->challenge);
        self::assertSame('S256', $pkce->method);
    }
}
