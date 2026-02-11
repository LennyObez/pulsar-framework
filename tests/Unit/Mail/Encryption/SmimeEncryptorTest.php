<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Mail\Encryption;

use OpenSSLAsymmetricKey;
use OpenSSLCertificateSigningRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Mail\Encryption\EncryptionType;
use Pulsar\Mail\Encryption\SmimeEncryptor;
use Pulsar\Mail\Exception\MailException;

use function dirname;
use function file_exists;
use function function_exists;
use function openssl_csr_new;
use function openssl_csr_sign;
use function openssl_pkey_new;
use function openssl_x509_export;

use const OPENSSL_KEYTYPE_RSA;
use const PHP_BINARY;

#[CoversClass(SmimeEncryptor::class)]
final class SmimeEncryptorTest extends TestCase
{
    #[Test]
    public function returnsSmimeEncryptionType(): void
    {
        if (!function_exists('openssl_pkcs7_encrypt')) {
            self::markTestSkipped('OpenSSL extension is not available');
        }

        $encryptor = new SmimeEncryptor();

        self::assertSame(EncryptionType::Smime, $encryptor->type());
    }

    #[Test]
    #[RequiresPhpExtension('openssl')]
    public function encryptsBodyWithValidCertificate(): void
    {
        $encryptor = new SmimeEncryptor();
        $certPem = $this->generateSelfSignedCertificate();
        $body = 'This is a secret message for S/MIME encryption testing.';

        $encrypted = $encryptor->encrypt($body, $certPem);

        self::assertNotSame($body, $encrypted);
        self::assertNotEmpty($encrypted);
    }

    #[Test]
    #[RequiresPhpExtension('openssl')]
    public function throwsOnInvalidCertificate(): void
    {
        $encryptor = new SmimeEncryptor();

        $this->expectException(MailException::class);
        $this->expectExceptionMessageMatches('/S\/MIME encryption failed/');

        $encryptor->encrypt('secret body', 'not-a-valid-certificate');
    }

    #[Test]
    #[RequiresPhpExtension('openssl')]
    public function producesDifferentOutputForDifferentBodies(): void
    {
        $encryptor = new SmimeEncryptor();
        $certPem = $this->generateSelfSignedCertificate();

        $encrypted1 = $encryptor->encrypt('Message A', $certPem);
        $encrypted2 = $encryptor->encrypt('Message B', $certPem);

        self::assertNotSame($encrypted1, $encrypted2);
    }

    /**
     * Generate a self-signed X.509 certificate for testing.
     */
    private function generateSelfSignedCertificate(): string
    {
        $opensslConfig = $this->resolveOpensslConfig();
        $keyOptions = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        if ($opensslConfig !== null) {
            $keyOptions['config'] = $opensslConfig;
        }

        $privateKey = openssl_pkey_new($keyOptions);

        self::assertNotFalse($privateKey, 'Failed to generate private key');

        $csrOptions = ['digest_alg' => 'sha256'];

        if ($opensslConfig !== null) {
            $csrOptions['config'] = $opensslConfig;
        }

        $csr = openssl_csr_new(
            ['commonName' => 'test@pulsar.dev'],
            $privateKey,
            $csrOptions,
        );

        self::assertInstanceOf(OpenSSLCertificateSigningRequest::class, $csr, 'Failed to generate CSR');
        self::assertInstanceOf(OpenSSLAsymmetricKey::class, $privateKey, 'Private key was invalidated');

        $x509 = openssl_csr_sign($csr, null, $privateKey, 1, $csrOptions);

        self::assertNotFalse($x509, 'Failed to sign certificate');

        $certPem = '';
        $exported = openssl_x509_export($x509, $certPem);

        self::assertTrue($exported, 'Failed to export certificate PEM');
        self::assertIsString($certPem, 'Certificate PEM must be a string');
        self::assertNotEmpty($certPem, 'Certificate PEM is empty');

        return $certPem;
    }

    /**
     * Locate the OpenSSL configuration file.
     *
     * On Windows (e.g. WAMP), the default openssl.cnf path is often wrong.
     * This method checks the PHP binary's extras/ssl directory as a fallback.
     */
    private function resolveOpensslConfig(): ?string
    {
        $phpDir = dirname(PHP_BINARY);
        $candidate = $phpDir . '/extras/ssl/openssl.cnf';

        if (file_exists($candidate)) {
            return $candidate;
        }

        return null;
    }
}
