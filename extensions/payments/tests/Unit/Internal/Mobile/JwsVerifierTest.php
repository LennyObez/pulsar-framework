<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Internal\Mobile;

use OpenSSLAsymmetricKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Exception\PaymentException;
use Pulsar\Extension\Payments\Internal\Mobile\JwsVerifier;

use function base64_encode;
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

use const JSON_THROW_ON_ERROR;
use const OPENSSL_ALGO_SHA256;
use const OPENSSL_KEYTYPE_EC;
use const STR_PAD_LEFT;

/**
 * The payments JwsVerifier now delegates to the shared, Apple-Root-CA-G3-pinned
 * X5cChainJwsVerifier (the correct crypto is exercised in
 * {@see \Pulsar\Tests\Unit\Security\Jws\X5cChainJwsVerifierTest}). These tests
 * confirm the delegation stays fail-closed and wraps failures as PaymentException.
 */
final class JwsVerifierTest extends TestCase
{
    #[Test]
    public function throwsOnEmptyToken(): void
    {
        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('empty JWS token');

        JwsVerifier::verifyAndDecode('');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unverifiableJws(): iterable
    {
        yield 'two parts' => ['a.b'];
        yield 'four parts' => ['a.b.c.d'];
        yield 'bad base64 header' => ['!!!.payload.sig'];
        yield 'non-ES256 alg' => [self::segment(['alg' => 'RS256', 'x5c' => ['x']]) . '.' . self::segment(['ok' => true]) . '.sig'];
        yield 'missing x5c' => [self::segment(['alg' => 'ES256']) . '.' . self::segment(['ok' => true]) . '.sig'];
    }

    #[Test]
    #[DataProvider('unverifiableJws')]
    public function throwsPaymentExceptionForUnverifiableJws(string $jws): void
    {
        $this->expectException(PaymentException::class);
        $this->expectExceptionMessageMatches('/JWS signature verification failed/');

        JwsVerifier::verifyAndDecode($jws);
    }

    #[Test]
    public function rejectsASelfSignedForgeryNotAnchoredToApple(): void
    {
        // Structurally valid, internally-consistent, self-signed ES256 JWS. It
        // does not chain to the bundled Apple Root CA G3, so it must be rejected
        // (the C13 attack: trusting x5c[0] would have accepted it).
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        self::assertInstanceOf(OpenSSLAsymmetricKey::class, $key);

        $csr = openssl_csr_new(['commonName' => 'attacker.example'], $key, ['digest_alg' => 'sha256']);
        self::assertNotFalse($csr);
        $cert = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);
        self::assertNotFalse($cert);
        self::assertTrue(openssl_x509_export($cert, $pem));

        $der = trim(str_replace(['-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----', "\n", "\r"], '', $pem));
        $header = self::segment(['alg' => 'ES256', 'x5c' => [$der]]);
        $payload = self::segment(['originalTransactionId' => 'victim']);

        openssl_sign("$header.$payload", $derSig, $key, OPENSSL_ALGO_SHA256);
        $jws = "$header.$payload." . self::b64url(self::derToJose($derSig));

        $this->expectException(PaymentException::class);
        $this->expectExceptionMessageMatches('/JWS signature verification failed/');

        JwsVerifier::verifyAndDecode($jws);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function segment(array $data): string
    {
        return self::b64url((string) json_encode($data, JSON_THROW_ON_ERROR));
    }

    private static function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function derToJose(string $der): string
    {
        $offset = 2;
        $rLen = ord($der[$offset + 1]);
        $r = substr($der, $offset + 2, $rLen);
        $offset += 2 + $rLen;
        $sLen = ord($der[$offset + 1]);
        $s = substr($der, $offset + 2, $sLen);

        return str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT) . str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
    }
}
