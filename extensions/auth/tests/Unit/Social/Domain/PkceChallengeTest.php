<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit\Social\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\Social\Domain\PkceChallenge;

use function strlen;

#[CoversClass(PkceChallenge::class)]
final class PkceChallengeTest extends TestCase
{
    #[Test]
    public function generateProducesValidS256Challenge(): void
    {
        $challenge = PkceChallenge::generate();

        self::assertSame('S256', $challenge->method);
        self::assertNotEmpty($challenge->verifier);
        self::assertNotEmpty($challenge->challenge);
        self::assertNotSame($challenge->verifier, $challenge->challenge);
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
    public function challengeIsSha256OfVerifier(): void
    {
        $challenge = PkceChallenge::generate();

        // Manually compute the expected challenge
        $expectedChallenge = rtrim(str_replace(['+', '/'], ['-', '_'], base64_encode(hash('sha256', $challenge->verifier, true))), '=');

        self::assertSame($expectedChallenge, $challenge->challenge);
    }

    #[Test]
    public function verifierLengthIs43Characters(): void
    {
        // 32 bytes → base64url without padding = 43 chars
        $challenge = PkceChallenge::generate();

        self::assertSame(43, strlen($challenge->verifier));
    }
}
