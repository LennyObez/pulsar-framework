<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\WebAuthn\Adapter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\WebAuthn\Adapter\AttestationVerifier;
use Pulsar\Extension\WebAuthn\Attestation\AttestationTrustLevel;
use Pulsar\Extension\WebAuthn\Exception\WebAuthnException;

use function chr;
use function strlen;

#[CoversClass(AttestationVerifier::class)]
final class AttestationVerifierTest extends TestCase
{
    #[Test]
    public function isFormatAllowedReturnsTrueForAllowedFormats(): void
    {
        $verifier = new AttestationVerifier(['none', 'packed']);

        self::assertTrue($verifier->isFormatAllowed('none'));
        self::assertTrue($verifier->isFormatAllowed('packed'));
        self::assertFalse($verifier->isFormatAllowed('android-key'));
        self::assertFalse($verifier->isFormatAllowed('tpm'));
    }

    #[Test]
    public function allowedFormatsReturnsConfiguredFormats(): void
    {
        $verifier = new AttestationVerifier(['none', 'packed', 'android-key']);

        self::assertSame(['none', 'packed', 'android-key'], $verifier->allowedFormats());
    }

    #[Test]
    public function defaultConstructorAllowsNoneAndPacked(): void
    {
        $verifier = new AttestationVerifier();

        self::assertSame(['none', 'packed'], $verifier->allowedFormats());
    }

    #[Test]
    public function verifyThrowsOnDisallowedFormat(): void
    {
        $verifier = new AttestationVerifier(['none']);

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage("'packed' is not allowed");
        $verifier->verify('packed', '', '');
    }

    #[Test]
    public function verifyNoneAttestationWithEmptyAttStmt(): void
    {
        $verifier = new AttestationVerifier(['none']);

        $rpIdHash = hash('sha256', 'example.com', true);
        $flags = "\x41"; // UP + AT flags
        $counter = "\x00\x00\x00\x00";
        $aaguid = str_repeat("\x00", 16);
        $credIdLen = "\x00\x04";
        $credId = 'cred';
        $authData = $rpIdHash . $flags . $counter . $aaguid . $credIdLen . $credId;

        $attestationObject = $this->buildCborAttestationObject('none', [], $authData);

        $result = $verifier->verify('none', $attestationObject, '{}');

        self::assertTrue($result->verified);
        self::assertSame('none', $result->format);
        self::assertSame(AttestationTrustLevel::None, $result->trustLevel);
    }

    #[Test]
    public function verifyNoneAttestationThrowsWhenAttStmtNotEmpty(): void
    {
        $verifier = new AttestationVerifier(['none']);

        $rpIdHash = hash('sha256', 'example.com', true);
        $flags = "\x41";
        $counter = "\x00\x00\x00\x00";
        $aaguid = str_repeat("\x00", 16);
        $credIdLen = "\x00\x04";
        $credId = 'cred';
        $authData = $rpIdHash . $flags . $counter . $aaguid . $credIdLen . $credId;

        $attestationObject = $this->buildCborAttestationObjectWithNonEmptyStmt($authData);

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('None attestation must have empty attStmt');
        $verifier->verify('none', $attestationObject, '{}');
    }

    #[Test]
    public function verifyPackedThrowsWhenMissingAlgAndSig(): void
    {
        $verifier = new AttestationVerifier(['packed']);

        $rpIdHash = hash('sha256', 'example.com', true);
        $flags = "\x41";
        $counter = "\x00\x00\x00\x00";
        $aaguid = str_repeat("\x00", 16);
        $credIdLen = "\x00\x04";
        $credId = 'cred';
        $authData = $rpIdHash . $flags . $counter . $aaguid . $credIdLen . $credId;

        $attestationObject = $this->buildCborAttestationObject('packed', [], $authData);

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('Packed attestation requires alg and sig');
        $verifier->verify('packed', $attestationObject, '{}');
    }

    #[Test]
    public function coseKeyBytesToPemReturnsNullForUnsupportedKeyType(): void
    {
        // CBOR map {1: 4, 3: -7}
        // a2 01 04 03 26
        $coseKey = "\xa2\x01\x04\x03\x26";

        self::assertNull(AttestationVerifier::coseKeyBytesToPem($coseKey));
    }

    #[Test]
    public function coseKeyBytesToPemReturnsNullForEc2WithMissingCoordinates(): void
    {
        // CBOR map {1: 2, 3: -7} (missing -2 and -3)
        // a2 01 02 03 26
        $coseKey = "\xa2\x01\x02\x03\x26";

        self::assertNull(AttestationVerifier::coseKeyBytesToPem($coseKey));
    }

    #[Test]
    public function coseKeyBytesToPemReturnsNullForRsaWithMissingComponents(): void
    {
        // CBOR map {1: 3} (RSA key type but missing -1 and -2)
        $coseKey = "\xa1\x01\x03";

        self::assertNull(AttestationVerifier::coseKeyBytesToPem($coseKey));
    }

    #[Test]
    public function coseKeyBytesToPemConvertsEc2Key(): void
    {
        // P-256 generator point (known valid EC point on prime256v1)
        $x = hex2bin('6b17d1f2e12c4247f8bce6e563a440f277037d812deb33a0f4a13945d898c296');
        self::assertIsString($x);
        $y = hex2bin('4fe342e2fe1a7f9b8ee7eb4a7c0f9e162bce33576b315ececbb6406837bf51f5');
        self::assertIsString($y);

        // Build CBOR map: {1: 2, 3: -7, -1: 1, -2: <x>, -3: <y>}
        $coseKey = $this->buildCborEc2Key($x, $y);

        $pem = AttestationVerifier::coseKeyBytesToPem($coseKey);
        self::assertNotNull($pem);
        self::assertStringContainsString('-----BEGIN PUBLIC KEY-----', $pem);

        $pubKey = openssl_pkey_get_public($pem);
        self::assertNotFalse($pubKey);
    }

    #[Test]
    public function aaguidFromAuthDataExtractsCorrectly(): void
    {
        $rpIdHash = hash('sha256', 'example.com', true);
        $flags = "\x41"; // UP + AT
        $counter = "\x00\x00\x00\x00";
        $aaguid = hex2bin('fbfc3007154e4ecc8c0b6e020557d7bd');
        $credIdLen = "\x00\x04";
        $credId = 'test';
        $authData = $rpIdHash . $flags . $counter . $aaguid . $credIdLen . $credId;

        $result = AttestationVerifier::aaguidFromAuthData($authData);
        self::assertSame('fbfc3007154e4ecc8c0b6e020557d7bd', $result);
    }

    #[Test]
    public function aaguidFromAuthDataReturnsZerosWhenDataTooShort(): void
    {
        $result = AttestationVerifier::aaguidFromAuthData(str_repeat("\x00", 10));
        self::assertSame(str_repeat('0', 32), $result);
    }

    #[Test]
    public function aaguidFromAuthDataReturnsZerosWhenNoAttestedDataFlag(): void
    {
        $rpIdHash = hash('sha256', 'example.com', true);
        $flags = "\x01"; // UP only, no AT flag
        $counter = "\x00\x00\x00\x00";
        $authData = $rpIdHash . $flags . $counter . str_repeat("\x00", 20);

        $result = AttestationVerifier::aaguidFromAuthData($authData);
        self::assertSame(str_repeat('0', 32), $result);
    }

    /**
     * Build a CBOR-encoded attestation object: {"fmt": $fmt, "attStmt": {}, "authData": $authData}
     *
     * @param array<string|int, mixed> $attStmt
     */
    private function buildCborAttestationObject(string $fmt, array $attStmt, string $authData): string
    {
        // CBOR map with 3 entries
        $cbor = "\xa3";
        // "fmt" => $fmt
        $cbor .= $this->cborText('fmt');
        $cbor .= $this->cborText($fmt);
        // "attStmt" => {} (empty map)
        $cbor .= $this->cborText('attStmt');
        $cbor .= "\xa0"; // empty map
        // "authData" => bytes
        $cbor .= $this->cborText('authData');
        $cbor .= $this->cborBytes($authData);

        return $cbor;
    }

    private function buildCborAttestationObjectWithNonEmptyStmt(string $authData): string
    {
        // CBOR map with 3 entries
        $cbor = "\xa3";
        $cbor .= $this->cborText('fmt');
        $cbor .= $this->cborText('none');
        $cbor .= $this->cborText('attStmt');
        // Non-empty attStmt: {"sig": "test"}
        $cbor .= "\xa1"; // map(1)
        $cbor .= $this->cborText('sig');
        $cbor .= $this->cborText('test');
        $cbor .= $this->cborText('authData');
        $cbor .= $this->cborBytes($authData);

        return $cbor;
    }

    private function buildCborEc2Key(string $x, string $y): string
    {
        // Map with 5 entries: {1: 2, 3: -7, -1: 1, -2: <x>, -3: <y>}
        $cbor = "\xa5";
        $cbor .= "\x01\x02"; // 1: 2
        $cbor .= "\x03\x26"; // 3: -7
        $cbor .= "\x20\x01"; // -1: 1
        $cbor .= "\x21";     // -2
        $cbor .= $this->cborBytes($x);
        $cbor .= "\x22";     // -3
        $cbor .= $this->cborBytes($y);

        return $cbor;
    }

    private function cborText(string $text): string
    {
        $len = strlen($text);

        if ($len < 24) {
            return chr(0x60 | $len) . $text;
        }

        if ($len < 256) {
            return "\x78" . chr($len) . $text;
        }

        return "\x79" . pack('n', $len) . $text;
    }

    private function cborBytes(string $bytes): string
    {
        $len = strlen($bytes);

        if ($len < 24) {
            return chr(0x40 | $len) . $bytes;
        }

        if ($len < 256) {
            return "\x58" . chr($len) . $bytes;
        }

        return "\x59" . pack('n', $len) . $bytes;
    }
}
