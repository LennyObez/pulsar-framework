<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Tests\Unit\Internal\Certificate;

use OpenSSLAsymmetricKey;
use OpenSSLCertificateSigningRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Psd2\Config\CertificateConfig;
use Pulsar\Extension\Psd2\Exception\Psd2Exception;
use Pulsar\Extension\Psd2\Internal\Certificate\DefaultCertificateValidator;

use function bin2hex;
use function file_put_contents;
use function implode;
use function openssl_csr_new;
use function openssl_csr_sign;
use function openssl_pkey_new;
use function openssl_x509_export;
use function random_bytes;
use function sys_get_temp_dir;
use function unlink;

use const OPENSSL_KEYTYPE_EC;

#[CoversClass(DefaultCertificateValidator::class)]
#[CoversClass(CertificateConfig::class)]
final class DefaultCertificateValidatorTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
    }

    #[Test]
    public function failsClosedWhenNoTrustListIsConfigured(): void
    {
        $validator = new DefaultCertificateValidator(new CertificateConfig());

        $this->expectException(Psd2Exception::class);
        $this->expectExceptionMessage('trust list');

        $validator->validate($this->selfSigned('Any Cert'));
    }

    #[Test]
    public function rejectsACertificateThatDoesNotChainToTheTrustList(): void
    {
        // The C7 exploit: a self-signed certificate carrying the right strings.
        // With a trust list that does NOT contain its issuer, it is refused
        // before any of its fields are read.
        $bundle = $this->bundle($this->selfSigned('A Different Root'));
        $validator = new DefaultCertificateValidator(
            new CertificateConfig(requireQualified: false, trustedCaBundlePath: $bundle),
        );

        $this->expectException(Psd2Exception::class);
        $this->expectExceptionMessage('does not chain');

        $validator->validate($this->selfSigned('Self-Signed Attacker'));
    }

    #[Test]
    public function acceptsACertificateThatChainsToTheTrustList(): void
    {
        $pem = $this->selfSigned('Trusted eIDAS Root');
        $bundle = $this->bundle($pem);

        $validator = new DefaultCertificateValidator(
            new CertificateConfig(requireQualified: false, trustedCaBundlePath: $bundle),
        );

        $info = $validator->validate($pem);

        self::assertSame('Trusted eIDAS Root', $info->subject);
    }

    private function selfSigned(string $cn): string
    {
        $key = $this->ecKey();
        $csr = openssl_csr_new(['commonName' => $cn], $key, ['digest_alg' => 'sha256']);
        self::assertInstanceOf(OpenSSLCertificateSigningRequest::class, $csr);
        self::assertInstanceOf(OpenSSLAsymmetricKey::class, $key);
        $cert = openssl_csr_sign($csr, null, $key, 3650, ['digest_alg' => 'sha256']);
        self::assertNotFalse($cert);
        $pem = '';
        self::assertTrue(openssl_x509_export($cert, $pem));

        return $pem;
    }

    private function ecKey(): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertInstanceOf(OpenSSLAsymmetricKey::class, $key);

        return $key;
    }

    private function bundle(string ...$pems): string
    {
        $path = sys_get_temp_dir() . '/psd2_ca_' . bin2hex(random_bytes(6)) . '.pem';
        file_put_contents($path, implode("\n", $pems));
        $this->tempFiles[] = $path;

        return $path;
    }
}
