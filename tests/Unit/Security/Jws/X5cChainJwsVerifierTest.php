<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Jws;

use DateTimeImmutable;
use OpenSSLAsymmetricKey;
use OpenSSLCertificateSigningRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Jws\JwsVerificationException;
use Pulsar\Security\Jws\X5cChainJwsVerifier;

use function base64_encode;
use function explode;
use function json_encode;
use function ltrim;
use function openssl_csr_new;
use function openssl_csr_sign;
use function openssl_pkey_new;
use function openssl_sign;
use function openssl_x509_export;
use function ord;
use function rtrim;
use function str_pad;
use function str_replace;
use function strtr;
use function substr;
use function trim;

use const OPENSSL_ALGO_SHA256;
use const OPENSSL_KEYTYPE_EC;
use const STR_PAD_LEFT;

#[CoversClass(X5cChainJwsVerifier::class)]
final class X5cChainJwsVerifierTest extends TestCase
{
    private OpenSSLAsymmetricKey $rootKey;
    private string $rootPem;
    private OpenSSLAsymmetricKey $intermediateKey;
    private string $intermediatePem;
    private OpenSSLAsymmetricKey $leafKey;
    private string $leafPem;

    protected function setUp(): void
    {
        $this->rootKey = $this->ecKey();
        $this->rootPem = $this->selfSigned($this->rootKey, 'Test Root CA');

        $this->intermediateKey = $this->ecKey();
        $this->intermediatePem = $this->signedBy($this->intermediateKey, 'Test WWDR', $this->rootKey, $this->rootPem);

        $this->leafKey = $this->ecKey();
        $this->leafPem = $this->signedBy($this->leafKey, 'Test Leaf', $this->intermediateKey, $this->intermediatePem);
    }

    #[Test]
    public function verifiesAGenuineChainAndReturnsThePayload(): void
    {
        $verifier = new X5cChainJwsVerifier([$this->rootPem]);

        $jws = $this->signJws(
            $this->leafKey,
            ['alg' => 'ES256', 'x5c' => $this->x5c($this->leafPem, $this->intermediatePem, $this->rootPem)],
            ['notificationType' => 'REFUND', 'originalTransactionId' => '1000'],
        );

        $payload = $verifier->verifyAndDecode($jws);

        self::assertSame('REFUND', $payload['notificationType']);
        self::assertSame('1000', $payload['originalTransactionId']);
    }

    #[Test]
    public function verifiesWhenTheSelfSignedRootIsOmittedFromX5c(): void
    {
        // Apple omits the self-signed root: the top presented cert (intermediate)
        // is directly signed by the pinned root, which the anchor pin accepts.
        $verifier = new X5cChainJwsVerifier([$this->rootPem]);

        $jws = $this->signJws(
            $this->leafKey,
            ['alg' => 'ES256', 'x5c' => $this->x5c($this->leafPem, $this->intermediatePem)],
            ['ok' => true],
        );

        self::assertTrue($verifier->verifyAndDecode($jws)['ok']);
    }

    #[Test]
    public function rejectsASelfSignedForgeryAgainstThePinnedRoot(): void
    {
        // The C13/C16 attack: attacker self-signs a leaf and puts it in x5c. With
        // a different pinned root the chain cannot anchor, so it is rejected even
        // though the signature over the payload is internally consistent.
        $attackerKey = $this->ecKey();
        $attackerPem = $this->selfSigned($attackerKey, 'attacker.example');

        $verifier = new X5cChainJwsVerifier([$this->rootPem]);

        $jws = $this->signJws(
            $attackerKey,
            ['alg' => 'ES256', 'x5c' => $this->x5c($attackerPem)],
            ['notificationType' => 'REFUND', 'originalTransactionId' => 'victim'],
        );

        $this->expectException(JwsVerificationException::class);
        $this->expectExceptionMessageMatches('/anchor/');

        $verifier->verifyAndDecode($jws);
    }

    #[Test]
    public function rejectsANonEs256Algorithm(): void
    {
        $verifier = new X5cChainJwsVerifier([$this->rootPem]);

        $jws = $this->signJws(
            $this->leafKey,
            ['alg' => 'none', 'x5c' => $this->x5c($this->leafPem, $this->intermediatePem, $this->rootPem)],
            ['ok' => true],
        );

        $this->expectException(JwsVerificationException::class);
        $this->expectExceptionMessageMatches('/algorithm/');

        $verifier->verifyAndDecode($jws);
    }

    #[Test]
    public function rejectsATamperedPayload(): void
    {
        $verifier = new X5cChainJwsVerifier([$this->rootPem]);

        $jws = $this->signJws(
            $this->leafKey,
            ['alg' => 'ES256', 'x5c' => $this->x5c($this->leafPem, $this->intermediatePem, $this->rootPem)],
            ['amount' => 1],
        );

        // Swap the payload segment for a different (validly-encoded) one.
        [$h, , $s] = explode('.', $jws);
        $forged = $h . '.' . $this->b64url((string) json_encode(['amount' => 9999])) . '.' . $s;

        $this->expectException(JwsVerificationException::class);
        $this->expectExceptionMessageMatches('/signature/');

        $verifier->verifyAndDecode($forged);
    }

    #[Test]
    public function rejectsACertificateOutsideItsValidityWindow(): void
    {
        $verifier = new X5cChainJwsVerifier([$this->rootPem]);

        $jws = $this->signJws(
            $this->leafKey,
            ['alg' => 'ES256', 'x5c' => $this->x5c($this->leafPem, $this->intermediatePem, $this->rootPem)],
            ['ok' => true],
        );

        // Evaluate 20 years hence, beyond the fixtures' validity.
        $this->expectException(JwsVerificationException::class);
        $this->expectExceptionMessageMatches('/validity/');

        $verifier->verifyAndDecode($jws, new DateTimeImmutable('+20 years'));
    }

    #[Test]
    public function rejectsAMalformedJws(): void
    {
        $verifier = new X5cChainJwsVerifier([$this->rootPem]);

        $this->expectException(JwsVerificationException::class);

        $verifier->verifyAndDecode('only.two');
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function ecKey(): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertInstanceOf(OpenSSLAsymmetricKey::class, $key);

        return $key;
    }

    private function selfSigned(OpenSSLAsymmetricKey $key, string $cn): string
    {
        $csr = openssl_csr_new(['commonName' => $cn], $key, ['digest_alg' => 'sha256']);
        self::assertInstanceOf(OpenSSLCertificateSigningRequest::class, $csr);
        // openssl_csr_new takes $key by reference; re-narrow it for the signer.
        self::assertInstanceOf(OpenSSLAsymmetricKey::class, $key);
        $cert = openssl_csr_sign($csr, null, $key, 3650, ['digest_alg' => 'sha256']);
        self::assertNotFalse($cert);
        $pem = '';
        self::assertTrue(openssl_x509_export($cert, $pem));
        self::assertIsString($pem);

        return $pem;
    }

    private function signedBy(
        OpenSSLAsymmetricKey $subjectKey,
        string $cn,
        OpenSSLAsymmetricKey $issuerKey,
        string $issuerCertPem,
    ): string {
        $csr = openssl_csr_new(['commonName' => $cn], $subjectKey, ['digest_alg' => 'sha256']);
        self::assertInstanceOf(OpenSSLCertificateSigningRequest::class, $csr);
        $cert = openssl_csr_sign($csr, $issuerCertPem, $issuerKey, 3650, ['digest_alg' => 'sha256']);
        self::assertNotFalse($cert);
        $pem = '';
        self::assertTrue(openssl_x509_export($cert, $pem));
        self::assertIsString($pem);

        return $pem;
    }

    /**
     * @return list<string>
     */
    private function x5c(string ...$pems): array
    {
        $entries = [];
        foreach ($pems as $pem) {
            $entries[] = trim(str_replace(
                ['-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----', "\n", "\r"],
                '',
                $pem,
            ));
        }

        return $entries;
    }

    /**
     * @param array<string, mixed> $header
     * @param array<string, mixed> $payload
     */
    private function signJws(OpenSSLAsymmetricKey $leafKey, array $header, array $payload): string
    {
        $signingInput = $this->b64url((string) json_encode($header)) . '.' . $this->b64url((string) json_encode($payload));

        $der = '';
        openssl_sign($signingInput, $der, $leafKey, OPENSSL_ALGO_SHA256);
        self::assertIsString($der);

        return $signingInput . '.' . $this->b64url($this->derToJose($der));
    }

    private function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * Convert an ASN.1 DER ECDSA signature to raw R||S (64 bytes for P-256).
     */
    private function derToJose(string $der): string
    {
        $offset = 2; // 0x30 seqLen (single byte for P-256)
        $rLen = ord($der[$offset + 1]);
        $r = substr($der, $offset + 2, $rLen);
        $offset += 2 + $rLen;
        $sLen = ord($der[$offset + 1]);
        $s = substr($der, $offset + 2, $sLen);

        $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
        $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);

        return $r . $s;
    }
}
