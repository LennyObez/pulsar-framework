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
use Pulsar\Extension\Psd2\Internal\Certificate\Revocation\RevocationCheckerInterface;
use Pulsar\Extension\Psd2\Internal\Certificate\Revocation\RevocationStatus;

use function bin2hex;
use function file_put_contents;
use function implode;
use function openssl_csr_new;
use function openssl_csr_sign;
use function openssl_pkey_new;
use function openssl_x509_export;
use function random_bytes;
use function sys_get_temp_dir;
use function tempnam;
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

    #[Test]
    public function rejectsARevokedCertificate(): void
    {
        [$leafPem, $bundle] = $this->caIssuedLeaf();
        $validator = new DefaultCertificateValidator(
            new CertificateConfig(requireQualified: false, trustedCaBundlePath: $bundle),
            revocationChecker: $this->revocation(RevocationStatus::Revoked),
        );

        $this->expectException(Psd2Exception::class);
        $this->expectExceptionMessage('has been revoked');

        $validator->validate($leafPem);
    }

    #[Test]
    public function acceptsACertificateConfirmedGoodByRevocation(): void
    {
        [$leafPem, $bundle] = $this->caIssuedLeaf();
        $validator = new DefaultCertificateValidator(
            new CertificateConfig(requireQualified: false, trustedCaBundlePath: $bundle),
            revocationChecker: $this->revocation(RevocationStatus::Good),
        );

        self::assertSame('Leaf', $validator->validate($leafPem)->subject);
    }

    #[Test]
    public function failsClosedWhenRevocationIsInconclusive(): void
    {
        [$leafPem, $bundle] = $this->caIssuedLeaf();
        $validator = new DefaultCertificateValidator(
            new CertificateConfig(requireQualified: false, trustedCaBundlePath: $bundle),
            revocationChecker: $this->revocation(RevocationStatus::Unknown),
        );

        $this->expectException(Psd2Exception::class);
        $this->expectExceptionMessage('could not be verified');

        $validator->validate($leafPem);
    }

    #[Test]
    public function toleratesInconclusiveRevocationWhenSoftFailIsEnabled(): void
    {
        [$leafPem, $bundle] = $this->caIssuedLeaf();
        $validator = new DefaultCertificateValidator(
            new CertificateConfig(requireQualified: false, trustedCaBundlePath: $bundle, revocationSoftFail: true),
            revocationChecker: $this->revocation(RevocationStatus::Unknown),
        );

        self::assertSame('Leaf', $validator->validate($leafPem)->subject);
    }

    #[Test]
    public function skipsRevocationForASelfSignedTrustAnchor(): void
    {
        // A self-signed root has no separate issuer to check against; revocation
        // is skipped (audited) even with a checker wired, and validation succeeds.
        $pem = $this->selfSigned('Trusted eIDAS Root');
        $validator = new DefaultCertificateValidator(
            new CertificateConfig(requireQualified: false, trustedCaBundlePath: $this->bundle($pem)),
            revocationChecker: $this->revocation(RevocationStatus::Revoked),
        );

        self::assertSame('Trusted eIDAS Root', $validator->validate($pem)->subject);
    }

    private function revocation(RevocationStatus $status): RevocationCheckerInterface
    {
        $checker = $this->createStub(RevocationCheckerInterface::class);
        $checker->method('check')->willReturn($status);

        return $checker;
    }

    /**
     * @return array{0: string, 1: string} [leaf PEM, CA bundle path]
     */
    private function caIssuedLeaf(): array
    {
        $cnf = "[req]\ndistinguished_name = dn\nprompt = no\n[dn]\nCN = fixture\n"
            . "[ca_ext]\nbasicConstraints = critical,CA:TRUE\nkeyUsage = critical,keyCertSign,cRLSign\n"
            . "[leaf_ext]\nbasicConstraints = CA:FALSE\n";
        $cnfPath = tempnam(sys_get_temp_dir(), 'psd2cnf') . '.cnf';
        file_put_contents($cnfPath, $cnf);
        $this->tempFiles[] = $cnfPath;
        $opts = ['config' => $cnfPath, 'digest_alg' => 'sha256'];

        $caKey = $this->ecKey();
        $caCsr = openssl_csr_new(['commonName' => 'Issuing CA'], $caKey, $opts);
        self::assertNotFalse($caCsr);
        $caCert = openssl_csr_sign($caCsr, null, $caKey, 3650, $opts + ['x509_extensions' => 'ca_ext'], 100);
        self::assertNotFalse($caCert);

        $leafKey = $this->ecKey();
        $leafCsr = openssl_csr_new(['commonName' => 'Leaf'], $leafKey, $opts);
        self::assertNotFalse($leafCsr);
        $leafCert = openssl_csr_sign($leafCsr, $caCert, $caKey, 365, $opts + ['x509_extensions' => 'leaf_ext'], 200);
        self::assertNotFalse($leafCert);

        $caPem = '';
        self::assertTrue(openssl_x509_export($caCert, $caPem));
        $leafPem = '';
        self::assertTrue(openssl_x509_export($leafCert, $leafPem));

        return [$leafPem, $this->bundle($caPem)];
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
