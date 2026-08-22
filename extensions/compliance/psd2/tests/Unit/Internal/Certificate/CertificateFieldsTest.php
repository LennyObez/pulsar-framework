<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Tests\Unit\Internal\Certificate;

use OpenSSLAsymmetricKey;
use OpenSSLCertificateSigningRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Psd2\Internal\Certificate\CertificateFields;

use function openssl_csr_new;
use function openssl_csr_sign;
use function openssl_pkey_new;
use function openssl_x509_export;
use function strlen;

use const OPENSSL_KEYTYPE_EC;

#[CoversClass(CertificateFields::class)]
final class CertificateFieldsTest extends TestCase
{
    #[Test]
    public function extractsIssuerSubjectSerialAndKeyHashFromARealCertificate(): void
    {
        $fields = CertificateFields::fromPem($this->selfSigned('Test Issuer'));
        self::assertInstanceOf(CertificateFields::class, $fields);

        // Self-signed: issuer Name DER equals subject Name DER.
        self::assertNotSame('', $fields->issuerNameDer());
        self::assertSame($fields->subjectNameDer(), $fields->issuerNameDer());

        self::assertNotSame('', $fields->serialNumberBytes());

        // issuerKeyHash is a SHA-1 digest (20 bytes) of the public key bit string.
        self::assertSame(20, strlen($fields->subjectPublicKeyHash()));

        // A basic self-signed certificate has no AIA extension.
        self::assertNull($fields->ocspResponderUrl());
    }

    #[Test]
    public function returnsNullForNonCertificateInput(): void
    {
        self::assertNull(CertificateFields::fromPem('not a certificate'));
    }

    private function selfSigned(string $cn): string
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertInstanceOf(OpenSSLAsymmetricKey::class, $key);
        $csr = openssl_csr_new(['commonName' => $cn], $key, ['digest_alg' => 'sha256']);
        self::assertInstanceOf(OpenSSLCertificateSigningRequest::class, $csr);
        self::assertInstanceOf(OpenSSLAsymmetricKey::class, $key);
        $cert = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);
        self::assertNotFalse($cert);
        $pem = '';
        self::assertTrue(openssl_x509_export($cert, $pem));

        return $pem;
    }
}
