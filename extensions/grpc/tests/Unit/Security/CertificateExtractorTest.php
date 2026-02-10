<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Security\CertificateExtractor;

use function extension_loaded;

#[CoversClass(CertificateExtractor::class)]
final class CertificateExtractorTest extends TestCase
{
    private CertificateExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new CertificateExtractor();
    }

    #[Test]
    public function extractSanReturnsNullForEmptyInput(): void
    {
        self::assertNull($this->extractor->extractSan(''));
    }

    #[Test]
    public function extractSanReturnsNullForWhitespaceInput(): void
    {
        self::assertNull($this->extractor->extractSan('   '));
    }

    #[Test]
    public function extractSanReturnsNullForInvalidCertificate(): void
    {
        self::assertNull($this->extractor->extractSan('not-a-certificate'));
    }

    #[Test]
    public function extractSanReturnsNullForMalformedPem(): void
    {
        $malformed = "-----BEGIN CERTIFICATE-----\nnotbase64\n-----END CERTIFICATE-----";

        self::assertNull($this->extractor->extractSan($malformed));
    }

    #[Test]
    public function extractSanReturnsDnsSanFromValidCertificate(): void
    {
        $cert = $this->generateSelfSignedCertWithSan('DNS:service.example.com');

        if ($cert === null) {
            self::markTestSkipped('OpenSSL extension not available or cannot generate test certificate.');
        }

        $san = $this->extractor->extractSan($cert);

        self::assertSame('service.example.com', $san);
    }

    #[Test]
    public function extractSanReturnsFirstDnsSanWhenMultipleExist(): void
    {
        $cert = $this->generateSelfSignedCertWithSan('DNS:first.example.com,DNS:second.example.com');

        if ($cert === null) {
            self::markTestSkipped('OpenSSL extension not available or cannot generate test certificate.');
        }

        $san = $this->extractor->extractSan($cert);

        self::assertSame('first.example.com', $san);
    }

    #[Test]
    public function extractSanSkipsNonDnsSanEntries(): void
    {
        $cert = $this->generateSelfSignedCertWithSan('email:admin@example.com,DNS:service.example.com');

        if ($cert === null) {
            self::markTestSkipped('OpenSSL extension not available or cannot generate test certificate.');
        }

        $san = $this->extractor->extractSan($cert);

        self::assertSame('service.example.com', $san);
    }

    #[Test]
    public function extractSanReturnsNullForCertWithoutSan(): void
    {
        $cert = $this->generateSelfSignedCertWithoutSan();

        if ($cert === null) {
            self::markTestSkipped('OpenSSL extension not available or cannot generate test certificate.');
        }

        $san = $this->extractor->extractSan($cert);

        self::assertNull($san);
    }

    /**
     * Generate a self-signed certificate with a specific SAN string for testing.
     */
    private function generateSelfSignedCertWithSan(string $sanValue): ?string
    {
        if (!extension_loaded('openssl')) {
            return null;
        }

        $configFile = tempnam(sys_get_temp_dir(), 'openssl_');

        if ($configFile === false) {
            return null;
        }

        file_put_contents($configFile, <<<CONF
            [req]
            distinguished_name = req_dn
            x509_extensions = v3_ext
            prompt = no

            [req_dn]
            CN = test

            [v3_ext]
            subjectAltName = {$sanValue}
            CONF);

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        if ($key === false) {
            unlink($configFile);

            return null;
        }

        $csr = openssl_csr_new(
            ['commonName' => 'test'],
            $key,
            ['config' => $configFile],
        );

        if ($csr === false) {
            unlink($configFile);

            return null;
        }

        $cert = openssl_csr_sign($csr, null, $key, 1, ['config' => $configFile, 'x509_extensions' => 'v3_ext']);

        unlink($configFile);

        if ($cert === false) {
            return null;
        }

        $pem = '';
        openssl_x509_export($cert, $pem);

        return $pem !== '' ? $pem : null;
    }

    /**
     * Generate a self-signed certificate without any SAN extension.
     */
    private function generateSelfSignedCertWithoutSan(): ?string
    {
        if (!extension_loaded('openssl')) {
            return null;
        }

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        if ($key === false) {
            return null;
        }

        $csr = openssl_csr_new(['commonName' => 'test-no-san'], $key);

        if ($csr === false) {
            return null;
        }

        $cert = openssl_csr_sign($csr, null, $key, 1);

        if ($cert === false) {
            return null;
        }

        $pem = '';
        openssl_x509_export($cert, $pem);

        return $pem !== '' ? $pem : null;
    }
}
