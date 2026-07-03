<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam\TimeTrap;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\AntiSpam\TimeTrap\TimeTrapService;
use Pulsar\Security\AntiSpam\TimeTrap\TimeTrapToken;

use function str_repeat;
use function substr;

#[CoversClass(TimeTrapService::class)]
#[CoversClass(TimeTrapToken::class)]
final class TimeTrapServiceTest extends TestCase
{
    private const string KEY = '0123456789abcdef0123456789abcdef'; // 32 bytes

    private const string OTHER_KEY = 'fedcba9876543210fedcba9876543210'; // 32 bytes

    #[Test]
    public function signThenParseRoundTripsIssuedAtAndFormId(): void
    {
        $service = new TimeTrapService(self::KEY);

        $token = $service->sign(new TimeTrapToken(issuedAt: 1_700_000_000, formId: 'contact'));
        $parsed = $service->parse($token);

        self::assertNotNull($parsed);
        self::assertSame(1_700_000_000, $parsed->issuedAt);
        self::assertSame('contact', $parsed->formId);
    }

    #[Test]
    public function parseRejectsATamperedSignature(): void
    {
        $service = new TimeTrapService(self::KEY);
        $token = $service->issue('contact');

        // Corrupt a character in the MIDDLE of the token, a fully-significant
        // base64url position. Flipping the FINAL character is unreliable: for
        // many blob lengths its low bits are base64 padding, so the flip can
        // decode to identical bytes and leave the MAC intact — an intermittent
        // false pass that depended on the time-varying signature's last char.
        $pos = intdiv(strlen($token), 2);
        $replacement = $token[$pos] === 'A' ? 'B' : 'A';
        $tampered = substr($token, 0, $pos) . $replacement . substr($token, $pos + 1);

        self::assertNull($service->parse($tampered));
    }

    #[Test]
    public function parseRejectsATokenSignedWithADifferentKey(): void
    {
        $signer = new TimeTrapService(self::KEY);
        $token = $signer->issue('contact');

        $verifier = new TimeTrapService(self::OTHER_KEY);

        self::assertNull($verifier->parse($token));
    }

    #[Test]
    public function parseRejectsGarbageAndOversizedInput(): void
    {
        $service = new TimeTrapService(self::KEY);

        self::assertNull($service->parse('not-a-token'));
        self::assertNull($service->parse(''));
        // A form id beyond the defence-in-depth bound is rejected on parse.
        self::assertNull($service->parse($service->issue(str_repeat('x', 200))));
    }

    #[Test]
    public function hasValidKeyReflectsKeyLength(): void
    {
        self::assertTrue(new TimeTrapService(self::KEY)->hasValidKey());
        self::assertFalse(new TimeTrapService('too-short')->hasValidKey());
    }
}
