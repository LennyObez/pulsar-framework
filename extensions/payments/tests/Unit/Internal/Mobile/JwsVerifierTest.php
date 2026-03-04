<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Internal\Mobile;

use OpenSSLAsymmetricKey;
use OpenSSLCertificateSigningRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Exception\PaymentException;
use Pulsar\Extension\Payments\Internal\Mobile\JwsVerifier;

use function base64_encode;
use function json_encode;
use function openssl_pkey_get_private;
use function openssl_pkey_new;
use function openssl_sign;
use function openssl_x509_export;
use function rtrim;
use function strtr;

use const JSON_THROW_ON_ERROR;
use const OPENSSL_ALGO_SHA256;

final class JwsVerifierTest extends TestCase
{
    #[Test]
    public function verifyAndDecodeAcceptsValidEs256Jws(): void
    {
        $jws = self::buildSignedJws(['productId' => 'com.example.premium', 'expiresDate' => 1700000000]);

        $decoded = JwsVerifier::verifyAndDecode($jws);

        self::assertSame('com.example.premium', $decoded['productId']);
        self::assertSame(1700000000, $decoded['expiresDate']);
    }

    #[Test]
    public function verifyAndDecodeThrowsOnEmptyToken(): void
    {
        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('empty JWS token');

        JwsVerifier::verifyAndDecode('');
    }

    #[Test]
    public function verifyAndDecodeThrowsOnInvalidPartCount(): void
    {
        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('JWS must have exactly 3 parts');

        JwsVerifier::verifyAndDecode('part1.part2');
    }

    #[Test]
    public function verifyAndDecodeThrowsOnFourParts(): void
    {
        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('JWS must have exactly 3 parts');

        JwsVerifier::verifyAndDecode('a.b.c.d');
    }

    #[Test]
    public function verifyAndDecodeThrowsOnInvalidBase64InHeader(): void
    {
        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('invalid base64url in header');

        JwsVerifier::verifyAndDecode('!!!.payload.signature');
    }

    #[Test]
    public function verifyAndDecodeThrowsOnNonEs256Algorithm(): void
    {
        $header = self::base64UrlEncode(json_encode(['alg' => 'RS256', 'x5c' => ['cert']], JSON_THROW_ON_ERROR));
        $payload = self::base64UrlEncode(json_encode(['data' => 'test'], JSON_THROW_ON_ERROR));

        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('unsupported algorithm: expected ES256, got RS256');

        JwsVerifier::verifyAndDecode("{$header}.{$payload}.signature");
    }

    #[Test]
    public function verifyAndDecodeThrowsOnMissingX5cChain(): void
    {
        $header = self::base64UrlEncode(json_encode(['alg' => 'ES256'], JSON_THROW_ON_ERROR));
        $payload = self::base64UrlEncode(json_encode(['data' => 'test'], JSON_THROW_ON_ERROR));

        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('x5c certificate chain is missing');

        JwsVerifier::verifyAndDecode("{$header}.{$payload}.signature");
    }

    #[Test]
    public function verifyAndDecodeThrowsOnEmptyX5cArray(): void
    {
        $header = self::base64UrlEncode(json_encode(['alg' => 'ES256', 'x5c' => []], JSON_THROW_ON_ERROR));
        $payload = self::base64UrlEncode(json_encode(['data' => 'test'], JSON_THROW_ON_ERROR));

        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('x5c certificate chain is missing');

        JwsVerifier::verifyAndDecode("{$header}.{$payload}.signature");
    }

    #[Test]
    public function verifyAndDecodeThrowsOnTamperedPayload(): void
    {
        $jws = self::buildSignedJws(['amount' => 100]);
        $parts = explode('.', $jws);

        $tamperedPayload = self::base64UrlEncode(json_encode(['amount' => 999], JSON_THROW_ON_ERROR));
        $tamperedJws = "{$parts[0]}.{$tamperedPayload}.{$parts[2]}";

        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('signature does not match');

        JwsVerifier::verifyAndDecode($tamperedJws);
    }

    #[Test]
    public function verifyAndDecodeThrowsOnTamperedSignature(): void
    {
        $jws = self::buildSignedJws(['key' => 'value']);
        $parts = explode('.', $jws);

        $corruptedSig = self::base64UrlEncode('this-is-not-a-valid-signature');
        $tamperedJws = "{$parts[0]}.{$parts[1]}.{$corruptedSig}";

        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('signature does not match');

        JwsVerifier::verifyAndDecode($tamperedJws);
    }

    #[Test]
    public function verifyAndDecodeThrowsOnMissingAlgorithm(): void
    {
        $header = self::base64UrlEncode(json_encode(['x5c' => ['cert']], JSON_THROW_ON_ERROR));
        $payload = self::base64UrlEncode(json_encode(['data' => 'test'], JSON_THROW_ON_ERROR));

        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('unsupported algorithm: expected ES256, got ');

        JwsVerifier::verifyAndDecode("{$header}.{$payload}.fakesig");
    }

    #[Test]
    public function verifyAndDecodePreservesAllPayloadFields(): void
    {
        $payload = [
            'notificationType' => 'DID_RENEW',
            'subtype' => 'BILLING_RECOVERY',
            'data' => ['signedTransactionInfo' => 'nested.jws.token'],
            'version' => '2.0',
        ];

        $jws = self::buildSignedJws($payload);
        $decoded = JwsVerifier::verifyAndDecode($jws);

        self::assertSame('DID_RENEW', $decoded['notificationType']);
        self::assertSame('BILLING_RECOVERY', $decoded['subtype']);
        self::assertSame('2.0', $decoded['version']);
        self::assertIsArray($decoded['data']);
    }

    #[Test]
    public function verifyAndDecodeReturnsEmptyArrayForEmptyJsonObject(): void
    {
        $jws = self::buildSignedJws([]);
        $decoded = JwsVerifier::verifyAndDecode($jws);

        self::assertSame([], $decoded);
    }

    #[Test]
    #[DataProvider('invalidJwsStructureProvider')]
    public function verifyAndDecodeRejectsInvalidStructure(string $jws, string $expectedMessage): void
    {
        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage($expectedMessage);

        JwsVerifier::verifyAndDecode($jws);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidJwsStructureProvider(): iterable
    {
        yield 'single part' => ['onlyonepart', 'JWS must have exactly 3 parts'];
        yield 'empty string' => ['', 'empty JWS token'];
        yield 'two dots no content' => ['..', 'invalid base64url in header'];
        yield 'invalid json in header' => [
            self::base64UrlEncode('not-json') . '.payload.sig',
            'header contains invalid JSON',
        ];
    }

    /**
     * Build a properly signed ES256 JWS for testing.
     *
     * Generates a fresh EC P-256 key pair and self-signed certificate,
     * then signs the payload and encodes the result as a JWS with x5c header.
     *
     * @param array<string, mixed> $payload
     */
    private static function buildSignedJws(array $payload): string
    {
        $ecKey = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        self::assertNotFalse($ecKey, 'Failed to generate EC key');

        $dn = ['commonName' => 'JwsVerifierTest'];
        $csr = openssl_csr_new($dn, $ecKey);
        self::assertNotFalse($csr, 'Failed to create CSR');
        /** @var OpenSSLCertificateSigningRequest $csr */

        /** @var OpenSSLAsymmetricKey $ecKey */
        $cert = openssl_csr_sign($csr, null, $ecKey, 365);
        self::assertNotFalse($cert, 'Failed to sign certificate');

        $certPem = '';
        openssl_x509_export($cert, $certPem);

        $certDer = '';
        /** @var string $certPem */
        $lines = explode("\n", $certPem);
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '' && !str_starts_with($trimmed, '-----')) {
                $certDer .= $trimmed;
            }
        }

        $headerData = [
            'alg' => 'ES256',
            'x5c' => [$certDer],
        ];

        $encodedHeader = self::base64UrlEncode(json_encode($headerData, JSON_THROW_ON_ERROR));
        $encodedPayload = self::base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));

        $signingInput = "{$encodedHeader}.{$encodedPayload}";
        $signature = '';

        /** @var OpenSSLAsymmetricKey $ecKey */
        $privateKey = openssl_pkey_get_private($ecKey);
        self::assertNotFalse($privateKey, 'Failed to get private key');

        $signed = openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        self::assertTrue($signed, 'Failed to sign JWS');

        /** @var string $signature */
        $encodedSignature = self::base64UrlEncode($signature);

        return "{$encodedHeader}.{$encodedPayload}.{$encodedSignature}";
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
