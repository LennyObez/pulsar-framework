<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Tests\Unit\Internal\Certificate\Revocation;

use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Psd2\Internal\Certificate\Asn1\DerEncoder;
use Pulsar\Extension\Psd2\Internal\Certificate\CertificateFields;
use Pulsar\Extension\Psd2\Internal\Certificate\Revocation\CrlRevocationChecker;
use Pulsar\Extension\Psd2\Internal\Certificate\Revocation\RevocationStatus;
use Pulsar\Http\Client\HttpClientInterface;
use Pulsar\Http\Client\HttpResponse;

use function chr;
use function file_put_contents;
use function gmdate;
use function ord;
use function sys_get_temp_dir;
use function tempnam;
use function time;
use function unlink;

use const OPENSSL_ALGO_SHA256;

#[CoversClass(CrlRevocationChecker::class)]
final class CrlRevocationCheckerTest extends TestCase
{
    private const string OID_SHA256_RSA = '1.2.840.113549.1.1.11';

    private string $cnfPath = '';
    private string $caPem = '';
    private string $caKeyPem = '';
    private string $leafPem = '';
    private string $leafNoCrlPem = '';
    private string $leafSerial = '';

    protected function setUp(): void
    {
        $cnf = <<<'CNF'
            [req]
            distinguished_name = dn
            prompt = no
            [dn]
            CN = fixture
            [ca_ext]
            basicConstraints = critical,CA:TRUE
            keyUsage = critical,keyCertSign,cRLSign
            [leaf_ext]
            basicConstraints = CA:FALSE
            crlDistributionPoints = URI:http://crl.fixture.invalid/ca.crl
            [leaf_no_crl_ext]
            basicConstraints = CA:FALSE
            CNF;

        $this->cnfPath = tempnam(sys_get_temp_dir(), 'crl') . '.cnf';
        file_put_contents($this->cnfPath, $cnf);
        $opts = ['config' => $this->cnfPath, 'digest_alg' => 'sha256'];

        $caKey = openssl_pkey_new(['config' => $this->cnfPath, 'private_key_bits' => 2048]);
        self::assertInstanceOf(OpenSSLAsymmetricKey::class, $caKey);
        $caCsr = openssl_csr_new(['commonName' => 'Fixture CA'], $caKey, $opts);
        self::assertNotFalse($caCsr);
        $caCert = openssl_csr_sign($caCsr, null, $caKey, 3650, $opts + ['x509_extensions' => 'ca_ext'], 1000);
        self::assertInstanceOf(OpenSSLCertificate::class, $caCert);

        [$this->leafPem, ] = $this->child($caCert, $caKey, 'leaf_ext', 5001, $opts);
        [$this->leafNoCrlPem, ] = $this->child($caCert, $caKey, 'leaf_no_crl_ext', 5002, $opts);

        self::assertTrue(openssl_x509_export($caCert, $this->caPem));
        self::assertTrue(openssl_pkey_export($caKey, $this->caKeyPem, null, ['config' => $this->cnfPath]));

        $leaf = CertificateFields::fromPem($this->leafPem);
        self::assertNotNull($leaf);
        $this->leafSerial = $leaf->serialNumberBytes();
    }

    protected function tearDown(): void
    {
        if ($this->cnfPath !== '') {
            @unlink($this->cnfPath);
        }
    }

    #[Test]
    public function reportsGoodWhenTheSerialIsNotOnAValidCurrentCrl(): void
    {
        $crl = $this->crl($this->caKeyPem, ["\x11\x22"]); // some other serial
        $status = $this->checkerFor($crl)->check($this->leafPem, $this->caPem);

        self::assertSame(RevocationStatus::Good, $status);
    }

    #[Test]
    public function reportsRevokedWhenTheSerialIsOnTheCrl(): void
    {
        $crl = $this->crl($this->caKeyPem, [$this->leafSerial]);
        $status = $this->checkerFor($crl)->check($this->leafPem, $this->caPem);

        self::assertSame(RevocationStatus::Revoked, $status);
    }

    #[Test]
    public function reportsGoodForAnEmptyRevokedList(): void
    {
        $crl = $this->crl($this->caKeyPem, []);
        $status = $this->checkerFor($crl)->check($this->leafPem, $this->caPem);

        self::assertSame(RevocationStatus::Good, $status);
    }

    #[Test]
    public function rejectsACrlNotSignedByTheIssuer(): void
    {
        // Sign the CRL with the leaf's key would need that key; instead tamper the
        // signature so it cannot verify against the CA.
        $crl = $this->crl($this->caKeyPem, [$this->leafSerial], tamper: true);

        self::assertSame(RevocationStatus::Unknown, $this->checkerFor($crl)->check($this->leafPem, $this->caPem));
    }

    #[Test]
    public function rejectsAStaleCrl(): void
    {
        $crl = $this->crl($this->caKeyPem, [], thisOffset: -14400, nextOffset: -7200);

        self::assertSame(RevocationStatus::Unknown, $this->checkerFor($crl)->check($this->leafPem, $this->caPem));
    }

    #[Test]
    public function returnsUnknownWhenTheLeafHasNoCrlDistributionPoint(): void
    {
        $checker = $this->checkerFor($this->crl($this->caKeyPem, []));

        self::assertSame(RevocationStatus::Unknown, $checker->check($this->leafNoCrlPem, $this->caPem));
    }

    #[Test]
    public function returnsUnknownWhenTheCrlIsUnreachable(): void
    {
        $client = $this->createStub(HttpClientInterface::class);
        $client->method('get')->willReturn(HttpResponse::fromRaw(404, [], ''));

        self::assertSame(RevocationStatus::Unknown, new CrlRevocationChecker($client)->check($this->leafPem, $this->caPem));
    }

    private function checkerFor(string $crlDer): CrlRevocationChecker
    {
        $client = $this->createStub(HttpClientInterface::class);
        $client->method('get')->willReturn(HttpResponse::fromRaw(200, [], $crlDer));

        return new CrlRevocationChecker($client);
    }

    /**
     * @param list<string> $revokedSerials Raw INTEGER value bytes of revoked serials
     */
    private function crl(
        string $signerKeyPem,
        array $revokedSerials,
        int $thisOffset = -3600,
        int $nextOffset = 3600,
        bool $tamper = false,
    ): string {
        $now = time();

        $issuerName = DerEncoder::sequence(
            DerEncoder::tlv(0x31, DerEncoder::sequence(
                DerEncoder::oid('2.5.4.3'),
                DerEncoder::tlv(0x13, 'Fixture CA'),
            )),
        );

        $entries = '';
        foreach ($revokedSerials as $serial) {
            $entries .= DerEncoder::sequence(
                DerEncoder::integerFromBytes($serial),
                DerEncoder::tlv(0x18, gmdate('YmdHis', $now - 7200) . 'Z'),
            );
        }
        $revokedList = $revokedSerials === [] ? '' : DerEncoder::sequence($entries);

        $tbs = DerEncoder::sequence(
            DerEncoder::algorithmIdentifier(self::OID_SHA256_RSA),
            $issuerName,
            DerEncoder::tlv(0x18, gmdate('YmdHis', $now + $thisOffset) . 'Z'),
            DerEncoder::tlv(0x18, gmdate('YmdHis', $now + $nextOffset) . 'Z'),
            $revokedList,
        );

        $signature = '';
        openssl_sign($tbs, $signature, $signerKeyPem, OPENSSL_ALGO_SHA256);

        if ($tamper) {
            $signature[0] = chr(ord($signature[0]) ^ 0xFF);
        }

        return DerEncoder::sequence(
            $tbs,
            DerEncoder::algorithmIdentifier(self::OID_SHA256_RSA),
            DerEncoder::tlv(0x03, "\x00" . $signature),
        );
    }

    /**
     * @param array{config: string, digest_alg: string} $opts
     *
     * @return array{0: string, 1: string}
     */
    private function child(OpenSSLCertificate $caCert, OpenSSLAsymmetricKey $caKey, string $section, int $serial, array $opts): array
    {
        $key = openssl_pkey_new(['config' => $opts['config'], 'private_key_bits' => 2048]);
        self::assertInstanceOf(OpenSSLAsymmetricKey::class, $key);
        $csr = openssl_csr_new(['commonName' => $section], $key, $opts);
        self::assertNotFalse($csr);
        $cert = openssl_csr_sign($csr, $caCert, $caKey, 365, $opts + ['x509_extensions' => $section], $serial);
        self::assertInstanceOf(OpenSSLCertificate::class, $cert);

        $pem = '';
        self::assertTrue(openssl_x509_export($cert, $pem));
        $keyPem = '';
        self::assertTrue(openssl_pkey_export($key, $keyPem, null, ['config' => $opts['config']]));

        return [$pem, $keyPem];
    }
}
