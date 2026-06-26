<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam\PrivacyPass;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\AntiSpam\PrivacyPass\PrivacyPassChallengeIssuer;
use Pulsar\Security\AntiSpam\PrivacyPass\TokenChallenge;

use function base64_decode;
use function preg_match;
use function strtr;

#[CoversClass(PrivacyPassChallengeIssuer::class)]
final class PrivacyPassChallengeIssuerTest extends TestCase
{
    #[Test]
    public function buildsTheWwwAuthenticateValueWithBothParameters(): void
    {
        $challenge = new TokenChallenge(0x0002, 'issuer.example', 'origin.example');
        $issuer = new PrivacyPassChallengeIssuer($challenge, Rfc9578TestVector::spkiDer());

        $header = $issuer->headerValue();

        self::assertStringStartsWith('PrivateToken ', $header);
        self::assertMatchesRegularExpression('/\bchallenge="[A-Za-z0-9\-_]+={0,2}"/', $header);
        self::assertMatchesRegularExpression('/\btoken-key="[A-Za-z0-9\-_]+={0,2}"/', $header);
    }

    #[Test]
    public function challengeParamDecodesBackToTheWireEncoding(): void
    {
        $challenge = Rfc9578TestVector::challenge();
        $issuer = new PrivacyPassChallengeIssuer($challenge, Rfc9578TestVector::spkiDer());

        self::assertSame(1, preg_match('/challenge="([^"]+)"/', $issuer->headerValue(), $m));
        $decoded = base64_decode(strtr($m[1] ?? '', '-_', '+/'), true);

        self::assertSame($challenge->encode(), $decoded);
    }

    #[Test]
    public function tokenKeyParamDecodesBackToTheSpki(): void
    {
        $issuer = new PrivacyPassChallengeIssuer(Rfc9578TestVector::challenge(), Rfc9578TestVector::spkiDer());

        self::assertSame(1, preg_match('/token-key="([^"]+)"/', $issuer->headerValue(), $m));
        $decoded = base64_decode(strtr($m[1] ?? '', '-_', '+/'), true);

        self::assertSame(Rfc9578TestVector::spkiDer(), $decoded);
    }
}
