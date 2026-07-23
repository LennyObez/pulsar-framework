<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ZeroTrust\DeviceIdentity;

use OpenSSLAsymmetricKey;
use OpenSSLCertificateSigningRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\ZeroTrust\DeviceIdentity\Internal\CborDecoder;
use Pulsar\Security\ZeroTrust\DeviceIdentity\Internal\WebAuthnAttestationVerifier;

use function base64_decode;
use function chr;
use function hash;
use function json_encode;
use function openssl_csr_new;
use function openssl_csr_sign;
use function openssl_pkey_get_details;
use function openssl_pkey_new;
use function openssl_sign;
use function openssl_x509_export;
use function preg_replace;
use function random_bytes;
use function str_repeat;
use function strlen;

use const OPENSSL_ALGO_SHA256;
use const OPENSSL_KEYTYPE_EC;

#[CoversClass(WebAuthnAttestationVerifier::class)]
#[CoversClass(CborDecoder::class)]
final class WebAuthnAttestationVerifierTest extends TestCase
{
    private const string CHALLENGE = 'test-challenge-value';
    private const string ORIGIN = 'https://example.com';

    private WebAuthnAttestationVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new WebAuthnAttestationVerifier();
    }

    #[Test]
    public function verifiesAGenuinePackedBasicAttestation(): void
    {
        $key = $this->ecKey();
        $certDer = $this->selfSignedCertDer($key);
        $authData = $this->authData();
        $clientDataJSON = $this->clientDataJSON();

        $sig = $this->sign($key, $authData . $this->clientDataHash($clientDataJSON));

        $result = $this->verifier->verify([
            'fmt' => 'packed',
            'authData' => $authData,
            'clientDataJSON' => $clientDataJSON,
            'attStmt' => ['alg' => -7, 'sig' => $sig, 'x5c' => [$certDer]],
        ], self::CHALLENGE, self::ORIGIN);

        self::assertTrue($result->verified);
        self::assertSame(0.9, $result->confidence);
    }

    #[Test]
    public function rejectsAForgedPackedSignature(): void
    {
        // C20 regression: a valid challenge/origin and a real certificate, but a
        // signature that does not verify. Previously this returned verified(0.9)
        // without ever checking the signature.
        $certDer = $this->selfSignedCertDer($this->ecKey());

        $result = $this->verifier->verify([
            'fmt' => 'packed',
            'authData' => $this->authData(),
            'clientDataJSON' => $this->clientDataJSON(),
            'attStmt' => ['alg' => -7, 'sig' => random_bytes(70), 'x5c' => [$certDer]],
        ], self::CHALLENGE, self::ORIGIN);

        self::assertFalse($result->verified);
        self::assertStringContainsString('signature', (string) $result->reason);
    }

    #[Test]
    public function verifiesAGenuinePackedSelfAttestation(): void
    {
        // No x5c: the signature is verified with the credential public key parsed
        // out of the authenticator data (exercises the CBOR / COSE-key path).
        $key = $this->ecKey();
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        /** @var array{ec: array{x: string, y: string}} $details */
        $authData = $this->authDataWithCredential($details['ec']['x'], $details['ec']['y']);
        $clientDataJSON = $this->clientDataJSON();

        $sig = $this->sign($key, $authData . $this->clientDataHash($clientDataJSON));

        $result = $this->verifier->verify([
            'fmt' => 'packed',
            'authData' => $authData,
            'clientDataJSON' => $clientDataJSON,
            'attStmt' => ['alg' => -7, 'sig' => $sig],
        ], self::CHALLENGE, self::ORIGIN);

        self::assertTrue($result->verified, (string) $result->reason);
    }

    #[Test]
    public function rejectsAChallengeMismatch(): void
    {
        $result = $this->verifier->verify([
            'fmt' => 'packed',
            'authData' => $this->authData(),
            'clientDataJSON' => $this->clientDataJSON(),
            'attStmt' => ['alg' => -7, 'sig' => random_bytes(70)],
        ], 'a-different-challenge', self::ORIGIN);

        self::assertFalse($result->verified);
        self::assertStringContainsString('Challenge', (string) $result->reason);
    }

    #[Test]
    public function rejectsAnOriginMismatch(): void
    {
        $result = $this->verifier->verify([
            'fmt' => 'packed',
            'authData' => $this->authData(),
            'clientDataJSON' => $this->clientDataJSON(),
            'attStmt' => ['alg' => -7, 'sig' => random_bytes(70)],
        ], self::CHALLENGE, 'https://evil.example');

        self::assertFalse($result->verified);
        self::assertStringContainsString('Origin', (string) $result->reason);
    }

    #[Test]
    public function noneAttestationIsAcceptedAtLowConfidence(): void
    {
        $result = $this->verifier->verify([
            'fmt' => 'none',
            'authData' => $this->authData(),
            'clientDataJSON' => $this->clientDataJSON(),
        ], self::CHALLENGE, self::ORIGIN);

        self::assertTrue($result->verified);
        self::assertSame(0.3, $result->confidence);
    }

    #[Test]
    public function rejectsAnUnsupportedFormat(): void
    {
        $result = $this->verifier->verify([
            'fmt' => 'tpm',
            'authData' => $this->authData(),
            'clientDataJSON' => $this->clientDataJSON(),
        ], self::CHALLENGE, self::ORIGIN);

        self::assertFalse($result->verified);
    }

    #[Test]
    public function rejectsMissingAuthenticatorData(): void
    {
        $result = $this->verifier->verify(
            ['fmt' => 'packed', 'clientDataJSON' => $this->clientDataJSON()],
            self::CHALLENGE,
            self::ORIGIN,
        );

        self::assertFalse($result->verified);
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function ecKey(): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertInstanceOf(OpenSSLAsymmetricKey::class, $key);

        return $key;
    }

    private function selfSignedCertDer(OpenSSLAsymmetricKey $key): string
    {
        $csr = openssl_csr_new(['commonName' => 'Attestation'], $key, ['digest_alg' => 'sha256']);
        self::assertInstanceOf(OpenSSLCertificateSigningRequest::class, $csr);
        self::assertInstanceOf(OpenSSLAsymmetricKey::class, $key);
        $cert = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);
        self::assertNotFalse($cert);
        $pem = '';
        self::assertTrue(openssl_x509_export($cert, $pem));

        $base64 = (string) preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $pem);
        $der = base64_decode($base64, true);
        self::assertIsString($der);

        return $der;
    }

    /** rpIdHash(32) ‖ flags=UP(0x01) ‖ signCount(4). */
    private function authData(): string
    {
        return hash('sha256', 'example.com', true) . "\x01" . "\x00\x00\x00\x00";
    }

    /** authData with the AT flag and attestedCredentialData carrying a COSE EC2 key. */
    private function authDataWithCredential(string $x, string $y): string
    {
        $rpIdHash = hash('sha256', 'example.com', true);
        $flags = "\x41"; // UP (0x01) | AT (0x40)
        $signCount = "\x00\x00\x00\x00";
        $aaguid = str_repeat("\x00", 16);
        $credentialId = random_bytes(16);
        $credIdLen = chr((strlen($credentialId) >> 8) & 0xFF) . chr(strlen($credentialId) & 0xFF);

        return $rpIdHash . $flags . $signCount . $aaguid . $credIdLen . $credentialId . $this->coseEc2Key($x, $y);
    }

    /** CBOR-encode a COSE EC2/P-256 public key: {1:2, 3:-7, -1:1, -2:x, -3:y}. */
    private function coseEc2Key(string $x, string $y): string
    {
        return "\xA5"                       // map(5)
            . "\x01\x02"                    // 1 (kty) => 2 (EC2)
            . "\x03\x26"                    // 3 (alg) => -7 (ES256)
            . "\x20\x01"                    // -1 (crv) => 1 (P-256)
            . "\x21\x58\x20" . $x           // -2 (x) => bytes(32)
            . "\x22\x58\x20" . $y;          // -3 (y) => bytes(32)
    }

    private function clientDataJSON(): string
    {
        return (string) json_encode([
            'type' => 'webauthn.create',
            'challenge' => self::CHALLENGE,
            'origin' => self::ORIGIN,
        ]);
    }

    private function clientDataHash(string $clientDataJSON): string
    {
        return hash('sha256', $clientDataJSON, true);
    }

    private function sign(OpenSSLAsymmetricKey $key, string $data): string
    {
        $sig = '';
        openssl_sign($data, $sig, $key, OPENSSL_ALGO_SHA256);
        self::assertIsString($sig);

        return $sig;
    }
}
