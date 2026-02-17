<?php

declare(strict_types=1);

namespace Pulsar\Extension\WebAuthn\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\WebAuthn\Adapter\AttestationVerifier;
use Pulsar\Extension\WebAuthn\Attestation\AttestationTrustLevel;
use Pulsar\Extension\WebAuthn\Exception\WebAuthnException;

use function chr;
use function count;
use function is_array;
use function is_int;
use function is_string;
use function strlen;

final class AttestationVerifierTest extends TestCase
{
    #[Test]
    public function isFormatAllowedReturnsTrueForAllowedFormats(): void
    {
        $verifier = new AttestationVerifier(['none', 'packed']);

        self::assertTrue($verifier->isFormatAllowed('none'));
        self::assertTrue($verifier->isFormatAllowed('packed'));
    }

    #[Test]
    public function isFormatAllowedReturnsFalseForDisallowedFormats(): void
    {
        $verifier = new AttestationVerifier(['none']);

        self::assertFalse($verifier->isFormatAllowed('packed'));
        self::assertFalse($verifier->isFormatAllowed('fido-u2f'));
    }

    #[Test]
    public function allowedFormatsReturnsConfiguredFormats(): void
    {
        $verifier = new AttestationVerifier(['none', 'packed']);

        self::assertSame(['none', 'packed'], $verifier->allowedFormats());
    }

    #[Test]
    public function verifyThrowsOnDisallowedFormat(): void
    {
        $verifier = new AttestationVerifier(['none']);

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage("Attestation format 'packed' is not allowed by policy");
        $verifier->verify('packed', '', '');
    }

    #[Test]
    public function verifyNoneWithEmptyAttStmtSucceeds(): void
    {
        $verifier = new AttestationVerifier(['none']);

        // Build a CBOR-encoded attestation object with fmt=none, attStmt={}, and minimal authData
        // authData: 32 bytes rpIdHash + flags(0x41: UP+AT) + 4 bytes counter + 16 bytes AAGUID
        $rpIdHash = hash('sha256', 'example.com', true); // 32 bytes
        $flags = "\x41"; // UP + AT flags set
        $counter = "\x00\x00\x00\x00";
        $aaguid = str_repeat("\x00", 16);
        $authData = $rpIdHash . $flags . $counter . $aaguid;

        // CBOR map: {fmt: "none", attStmt: {}, authData: <bytes>}
        $attObjCbor = $this->buildAttestationObjectCbor('none', [], $authData);

        $result = $verifier->verify('none', $attObjCbor, '{}');

        self::assertTrue($result->verified);
        self::assertSame('none', $result->format);
        self::assertSame(AttestationTrustLevel::None, $result->trustLevel);
    }

    #[Test]
    public function verifyNoneWithNonEmptyAttStmtThrows(): void
    {
        $verifier = new AttestationVerifier(['none']);

        // Build attestation object with non-empty attStmt
        $rpIdHash = hash('sha256', 'example.com', true);
        $flags = "\x41";
        $counter = "\x00\x00\x00\x00";
        $aaguid = str_repeat("\x00", 16);
        $authData = $rpIdHash . $flags . $counter . $aaguid;

        $attObjCbor = $this->buildAttestationObjectCbor('none', ['sig' => 'test'], $authData);

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('None attestation must have empty attStmt');
        $verifier->verify('none', $attObjCbor, '{}');
    }

    #[Test]
    public function verifyThrowsOnUnsupportedFormatInMatchBlock(): void
    {
        // Allow 'android-key' format but it's not handled in the match block
        $verifier = new AttestationVerifier(['android-key']);

        $rpIdHash = hash('sha256', 'example.com', true);
        $flags = "\x41";
        $counter = "\x00\x00\x00\x00";
        $aaguid = str_repeat("\x00", 16);
        $authData = $rpIdHash . $flags . $counter . $aaguid;

        $attObjCbor = $this->buildAttestationObjectCbor('android-key', [], $authData);

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('Unsupported format: android-key');
        $verifier->verify('android-key', $attObjCbor, '{}');
    }

    #[Test]
    public function extractAaguidReturnsZeroesForShortAuthData(): void
    {
        // Static method aaguidFromAuthData with short data
        $result = AttestationVerifier::aaguidFromAuthData('short');

        self::assertSame(str_repeat('0', 32), $result);
    }

    #[Test]
    public function extractAaguidReturnsZeroesWhenAttestedDataFlagNotSet(): void
    {
        // 32 bytes rpIdHash + flags byte (0x01 = UP only, no AT) + 4 bytes counter = 37 bytes
        $rpIdHash = str_repeat("\x00", 32);
        $flags = "\x01"; // UP only, AT flag (0x40) not set
        $counter = "\x00\x00\x00\x00";
        $aaguid = str_repeat("\xab", 16);
        $authData = $rpIdHash . $flags . $counter . $aaguid;

        $result = AttestationVerifier::aaguidFromAuthData($authData);

        self::assertSame(str_repeat('0', 32), $result);
    }

    #[Test]
    public function extractAaguidReturnsHexWhenAttestedDataFlagSet(): void
    {
        $rpIdHash = str_repeat("\x00", 32);
        $flags = "\x41"; // UP + AT flags
        $counter = "\x00\x00\x00\x00";
        $aaguid = "\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10";
        $authData = $rpIdHash . $flags . $counter . $aaguid;

        $result = AttestationVerifier::aaguidFromAuthData($authData);

        self::assertSame('0102030405060708090a0b0c0d0e0f10', $result);
    }

    #[Test]
    public function coseKeyBytesToPemReturnsNullForUnknownKeyType(): void
    {
        // CBOR map with kty=4 (unknown type): {1: 4}
        $coseKey = "\xa1\x01\x04";

        $result = AttestationVerifier::coseKeyBytesToPem($coseKey);

        self::assertNull($result);
    }

    #[Test]
    public function coseKeyBytesToPemReturnsNullForEc2MissingCoordinates(): void
    {
        // CBOR map with kty=2 (EC2) but no x/y: {1: 2}
        $coseKey = "\xa1\x01\x02";

        $result = AttestationVerifier::coseKeyBytesToPem($coseKey);

        self::assertNull($result);
    }

    #[Test]
    public function coseKeyBytesToPemReturnsNullForRsaMissingComponents(): void
    {
        // CBOR map with kty=3 (RSA) but no n/e: {1: 3}
        $coseKey = "\xa1\x01\x03";

        $result = AttestationVerifier::coseKeyBytesToPem($coseKey);

        self::assertNull($result);
    }

    #[Test]
    public function coseKeyBytesToPemGeneratesEc2Pem(): void
    {
        // CBOR map: {1: 2, -2: <32 bytes x>, -3: <32 bytes y>}
        // kty=2 (EC2), x and y coordinates for P-256
        $x = str_repeat("\x01", 32);
        $y = str_repeat("\x02", 32);

        // Build CBOR: a3 01 02 21 5820 <x> 22 5820 <y>
        // key 1 (kty): 0x01, value 2: 0x02
        // key -2: 0x21, value bstr(32): 0x5820
        // key -3: 0x22, value bstr(32): 0x5820
        $coseKey = "\xa3\x01\x02\x21\x58\x20" . $x . "\x22\x58\x20" . $y;

        $result = AttestationVerifier::coseKeyBytesToPem($coseKey);

        self::assertNotNull($result);
        self::assertStringContainsString('-----BEGIN PUBLIC KEY-----', $result);
        self::assertStringContainsString('-----END PUBLIC KEY-----', $result);
    }

    #[Test]
    public function coseKeyBytesToPemGeneratesRsaPem(): void
    {
        // CBOR map: {1: 3, -1: <modulus>, -2: <exponent>}
        // kty=3 (RSA) - use small key for test (avoid uint16 CBOR path)
        $n = str_repeat("\x01", 16); // Small test modulus
        $e = "\x01\x00\x01"; // exponent 65537

        // Build CBOR: a3 01 03 20 50 <n:16 bytes> 21 43 <e:3 bytes>
        // key 1: 0x01, value 3: 0x03
        // key -1: 0x20, value bstr(16): 0x50
        // key -2: 0x21, value bstr(3): 0x43
        $coseKey = "\xa3\x01\x03\x20\x50" . $n . "\x21\x43" . $e;

        $result = AttestationVerifier::coseKeyBytesToPem($coseKey);

        self::assertNotNull($result);
        self::assertStringContainsString('-----BEGIN PUBLIC KEY-----', $result);
        self::assertStringContainsString('-----END PUBLIC KEY-----', $result);
    }

    #[Test]
    public function extractPublicKeyFromAuthDataReturnsNullForShortData(): void
    {
        // Test via verifyPackedSelf by testing extractPublicKeyFromAuthData indirectly
        // AuthData shorter than 55 bytes should return null
        $verifier = new AttestationVerifier(['packed']);

        // Build packed attestation with very short authData (no credential data)
        $shortAuthData = str_repeat("\x00", 37);
        $attObjCbor = $this->buildAttestationObjectCbor('packed', ['alg' => -7, 'sig' => 'fakesig'], $shortAuthData);

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('Cannot extract credential public key from authenticator data');
        $verifier->verify('packed', $attObjCbor, '{}');
    }

    #[Test]
    public function verifyPackedRequiresAlgAndSig(): void
    {
        $verifier = new AttestationVerifier(['packed']);

        $rpIdHash = hash('sha256', 'example.com', true);
        $flags = "\x41";
        $counter = "\x00\x00\x00\x00";
        $aaguid = str_repeat("\x00", 16);
        $authData = $rpIdHash . $flags . $counter . $aaguid;

        // attStmt without alg or sig
        $attObjCbor = $this->buildAttestationObjectCbor('packed', [], $authData);

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('Packed attestation requires alg and sig');
        $verifier->verify('packed', $attObjCbor, '{}');
    }

    #[Test]
    public function derLengthHandlesShortLength(): void
    {
        // Test via EC2 key which exercises derLength with values < 0x80
        $x = str_repeat("\x01", 32);
        $y = str_repeat("\x02", 32);
        $coseKey = "\xa3\x01\x02\x21\x58\x20" . $x . "\x22\x58\x20" . $y;

        $result = AttestationVerifier::coseKeyBytesToPem($coseKey);

        self::assertNotNull($result);
        self::assertStringContainsString('-----BEGIN PUBLIC KEY-----', $result);
    }

    #[Test]
    public function derIntegerAddsLeadingZeroForHighBit(): void
    {
        // Test via RSA key where modulus has high bit set (0x80+)
        $n = "\x80" . str_repeat("\x01", 127); // High bit set -> needs leading zero
        $e = "\x01\x00\x01";

        $coseKey = "\xa3\x01\x03\x20\x58\x80" . $n . "\x21\x43" . $e;

        $result = AttestationVerifier::coseKeyBytesToPem($coseKey);

        self::assertNotNull($result);
        self::assertStringContainsString('-----BEGIN PUBLIC KEY-----', $result);
    }

    #[Test]
    public function defaultConstructorAllowsNoneAndPacked(): void
    {
        $verifier = new AttestationVerifier();

        self::assertTrue($verifier->isFormatAllowed('none'));
        self::assertTrue($verifier->isFormatAllowed('packed'));
        self::assertFalse($verifier->isFormatAllowed('fido-u2f'));
    }

    /**
     * Build a CBOR-encoded attestation object.
     *
     * @param array<string|int, mixed> $attStmt
     */
    private function buildAttestationObjectCbor(string $fmt, array $attStmt, string $authData): string
    {
        // Build a minimal CBOR map with 3 keys: fmt, attStmt, authData
        $encoded = "\xa3"; // map(3)

        // "fmt" key
        $encoded .= $this->cborTextString('fmt');
        $encoded .= $this->cborTextString($fmt);

        // "attStmt" key
        $encoded .= $this->cborTextString('attStmt');
        $encoded .= $this->cborMap($attStmt);

        // "authData" key
        $encoded .= $this->cborTextString('authData');
        $encoded .= $this->cborByteString($authData);

        return $encoded;
    }

    private function cborTextString(string $value): string
    {
        $len = strlen($value);

        if ($len < 24) {
            return chr(0x60 | $len) . $value;
        }

        if ($len < 256) {
            return "\x78" . chr($len) . $value;
        }

        return "\x79" . pack('n', $len) . $value;
    }

    private function cborByteString(string $value): string
    {
        $len = strlen($value);

        if ($len < 24) {
            return chr(0x40 | $len) . $value;
        }

        if ($len < 256) {
            return "\x58" . chr($len) . $value;
        }

        return "\x59" . pack('n', $len) . $value;
    }

    /**
     * @param array<string|int, mixed> $map
     */
    private function cborMap(array $map): string
    {
        $count = count($map);

        if ($count === 0) {
            return "\xa0";
        }

        $encoded = chr((0xa0 | min($count, 23)) & 0xFF);

        foreach ($map as $key => $value) {
            if (is_string($key)) {
                $encoded .= $this->cborTextString($key);
            } else {
                if ($key >= 0) {
                    if ($key < 24) {
                        $encoded .= chr($key & 0xFF);
                    } else {
                        $encoded .= "\x18" . chr($key & 0xFF);
                    }
                } else {
                    // Negative CBOR keys: -1 encodes as 0x20, -2 as 0x21, etc.
                    $negVal = -1 - $key;
                    $encoded .= chr((0x20 | ($negVal & 0x1F)) & 0xFF);
                }
            }

            if (is_string($value)) {
                $encoded .= $this->cborByteString($value);
            } elseif (is_int($value)) {
                if ($value >= 0 && $value < 24) {
                    $encoded .= chr($value & 0xFF);
                } elseif ($value >= 0) {
                    $encoded .= "\x18" . chr($value & 0xFF);
                } else {
                    // Negative CBOR values: -1 encodes as 0x20, -2 as 0x21, etc.
                    $negVal = -1 - $value;
                    $encoded .= chr((0x20 | ($negVal & 0x1F)) & 0xFF);
                }
            } elseif (is_array($value)) {
                $encoded .= $this->cborMap($value);
            }
        }

        return $encoded;
    }
}
