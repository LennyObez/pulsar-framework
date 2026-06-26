<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam\PrivacyPass;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\AntiSpam\PrivacyPass\TokenChallenge;

use function hash;
use function str_repeat;
use function substr;

#[CoversClass(TokenChallenge::class)]
final class TokenChallengeTest extends TestCase
{
    #[Test]
    public function encodesToTheRfc9577WireFormat(): void
    {
        $challenge = Rfc9578TestVector::challenge();

        self::assertSame(
            Rfc9578TestVector::tokenChallengeWire(),
            $challenge->encode(),
            'encoding must match the RFC 9578 test vector byte-for-byte',
        );
    }

    #[Test]
    public function digestIsSha256OfTheWireEncoding(): void
    {
        $challenge = Rfc9578TestVector::challenge();

        self::assertSame(hash('sha256', $challenge->encode(), true), $challenge->digest());
    }

    #[Test]
    public function encodesAnEmptyRedemptionContextAsASingleZeroOctet(): void
    {
        $challenge = new TokenChallenge(0x0002, 'issuer.example', 'origin.example');

        // type(2) + len(2)+14 + 0x00 + len(2)+14
        $encoded = $challenge->encode();

        self::assertSame("\x00\x02", substr($encoded, 0, 2));
        self::assertSame("\x00", substr($encoded, 2 + 2 + 14, 1), 'empty context is a single 0x00 length octet');
    }

    #[Test]
    public function rejectsARedemptionContextThatIsNeitherEmptyNor32Bytes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TokenChallenge(0x0002, 'issuer.example', '', str_repeat('x', 16));
    }

    #[Test]
    public function rejectsAnEmptyIssuerName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TokenChallenge(0x0002, '');
    }

    #[Test]
    public function rejectsATokenTypeOutsideUint16(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TokenChallenge(0x10000, 'issuer.example');
    }
}
