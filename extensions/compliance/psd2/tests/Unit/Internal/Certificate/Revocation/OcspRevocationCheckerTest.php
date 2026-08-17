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
use Pulsar\Extension\Psd2\Internal\Certificate\Revocation\OcspRevocationChecker;
use Pulsar\Extension\Psd2\Internal\Certificate\Revocation\RevocationStatus;
use Pulsar\Http\Client\HttpClientInterface;
use Pulsar\Http\Client\HttpResponse;

use function chr;
use function file_put_contents;
use function gmdate;
use function ord;
use function sha1;
use function sys_get_temp_dir;
use function tempnam;
use function time;
use function unlink;

use const OPENSSL_ALGO_SHA256;

#[CoversClass(OcspRevocationChecker::class)]
final class OcspRevocationCheckerTest extends TestCase
{
    private const string OID_SHA1 = '1.3.14.3.2.26';
    private const string OID_SHA256_RSA = '1.2.840.113549.1.1.11';
    private const string OID_OCSP_BASIC = '1.3.6.1.5.5.7.48.1.1';
    private const string OID_OCSP_NONCE = '1.3.6.1.5.5.7.48.1.2';

    private string $cnfPath = '';
    private string $caPem = '';
    private string $caKeyPem = '';
    private string $leafPem = '';
    private string $leafKeyPem = '';
    private string $leafNoAiaPem = '';
    private string $responderPem = '';
    private string $responderKeyPem = '';

    private string $certIdDer = '';
    private string $caKeyHash = '';
    private string $caNameDer = '';
    private string $responderKeyHash = '';
    private string $leafKeyHash = '';

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
            authorityInfoAccess = OCSP;URI:http://ocsp.fixture.invalid/
            [leaf_no_aia_ext]
            basicConstraints = CA:FALSE
            [responder_ext]
            basicConstraints = CA:FALSE
            extendedKeyUsage = OCSPSigning
            CNF;

        $this->cnfPath = tempnam(sys_get_temp_dir(), 'ocsp') . '.cnf';
        file_put_contents($this->cnfPath, $cnf);
        $opts = ['config' => $this->cnfPath, 'digest_alg' => 'sha256'];

        [$this->caPem, $this->caKeyPem, $caCert, $caKey] = $this->selfSignedCa($opts);
        [$this->leafPem, $this->leafKeyPem] = $this->childCert($caCert, $caKey, 'leaf_ext', 2001, $opts);
        [$this->leafNoAiaPem] = $this->childCert($caCert, $caKey, 'leaf_no_aia_ext', 2002, $opts);
        [$this->responderPem, $this->responderKeyPem] = $this->childCert($caCert, $caKey, 'responder_ext', 3001, $opts);

        $leaf = CertificateFields::fromPem($this->leafPem);
        $ca = CertificateFields::fromPem($this->caPem);
        $responder = CertificateFields::fromPem($this->responderPem);
        $leafSigner = CertificateFields::fromPem($this->leafNoAiaPem);
        self::assertNotNull($leaf);
        self::assertNotNull($ca);
        self::assertNotNull($responder);
        self::assertNotNull($leafSigner);

        $this->caKeyHash = $ca->subjectPublicKeyHash();
        $this->caNameDer = $ca->subjectNameDer();
        $this->responderKeyHash = $responder->subjectPublicKeyHash();
        $this->leafKeyHash = $leafSigner->subjectPublicKeyHash();

        $this->certIdDer = DerEncoder::sequence(
            DerEncoder::algorithmIdentifier(self::OID_SHA1),
            DerEncoder::octetString(sha1($leaf->issuerNameDer(), true)),
            DerEncoder::octetString($ca->subjectPublicKeyHash()),
            DerEncoder::integerFromBytes($leaf->serialNumberBytes()),
        );
    }

    protected function tearDown(): void
    {
        if ($this->cnfPath !== '') {
            @unlink($this->cnfPath);
        }
    }

    #[Test]
    public function returnsGoodForAValidDirectlySignedResponse(): void
    {
        $response = $this->ocspResponse(
            signerKeyPem: $this->caKeyPem,
            responderId: DerEncoder::explicit(2, DerEncoder::octetString($this->caKeyHash)),
            certStatus: DerEncoder::tlv(0x80, ''),
        );

        self::assertSame(RevocationStatus::Good, $this->check($response));
    }

    #[Test]
    public function returnsRevokedWhenTheResponderReportsRevocation(): void
    {
        $response = $this->ocspResponse(
            signerKeyPem: $this->caKeyPem,
            responderId: DerEncoder::explicit(2, DerEncoder::octetString($this->caKeyHash)),
            certStatus: DerEncoder::tlv(0xA1, DerEncoder::tlv(0x18, gmdate('YmdHis', time() - 7200) . 'Z')),
        );

        self::assertSame(RevocationStatus::Revoked, $this->check($response));
    }

    #[Test]
    public function returnsUnknownForAnUnknownCertStatus(): void
    {
        $response = $this->ocspResponse(
            signerKeyPem: $this->caKeyPem,
            responderId: DerEncoder::explicit(2, DerEncoder::octetString($this->caKeyHash)),
            certStatus: DerEncoder::tlv(0x82, ''),
        );

        self::assertSame(RevocationStatus::Unknown, $this->check($response));
    }

    #[Test]
    public function rejectsATamperedSignature(): void
    {
        $response = $this->ocspResponse(
            signerKeyPem: $this->caKeyPem,
            responderId: DerEncoder::explicit(2, DerEncoder::octetString($this->caKeyHash)),
            certStatus: DerEncoder::tlv(0x80, ''),
            tamper: true,
        );

        self::assertSame(RevocationStatus::Unknown, $this->check($response));
    }

    #[Test]
    public function rejectsAResponseSignedByAnUntrustedKey(): void
    {
        // Signed with the leaf's own key but claiming the CA as responder: the
        // signature cannot verify against the CA's public key.
        $response = $this->ocspResponse(
            signerKeyPem: $this->leafKeyPem,
            responderId: DerEncoder::explicit(2, DerEncoder::octetString($this->caKeyHash)),
            certStatus: DerEncoder::tlv(0x80, ''),
        );

        self::assertSame(RevocationStatus::Unknown, $this->check($response));
    }

    #[Test]
    public function rejectsAMismatchedNonce(): void
    {
        $response = $this->ocspResponse(
            signerKeyPem: $this->caKeyPem,
            responderId: DerEncoder::explicit(2, DerEncoder::octetString($this->caKeyHash)),
            certStatus: DerEncoder::tlv(0x80, ''),
            echoNonce: "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F",
        );

        self::assertSame(RevocationStatus::Unknown, $this->check($response));
    }

    #[Test]
    public function rejectsAStaleResponse(): void
    {
        $response = $this->ocspResponse(
            signerKeyPem: $this->caKeyPem,
            responderId: DerEncoder::explicit(2, DerEncoder::octetString($this->caKeyHash)),
            certStatus: DerEncoder::tlv(0x80, ''),
            thisUpdateOffset: -14400,
            nextUpdateOffset: -7200,
        );

        self::assertSame(RevocationStatus::Unknown, $this->check($response));
    }

    #[Test]
    public function acceptsAValidDelegatedResponder(): void
    {
        $response = $this->ocspResponse(
            signerKeyPem: $this->responderKeyPem,
            responderId: DerEncoder::explicit(2, DerEncoder::octetString($this->responderKeyHash)),
            certStatus: DerEncoder::tlv(0x80, ''),
            includeCertPem: $this->responderPem,
        );

        self::assertSame(RevocationStatus::Good, $this->check($response));
    }

    #[Test]
    public function rejectsADelegatedResponderWithoutOcspSigningEku(): void
    {
        // Signed by the CA-issued leaf key, but the leaf lacks the OCSP-signing
        // EKU, so it is not a legitimate delegated responder (RFC 6960 §4.2.2.2).
        $response = $this->ocspResponse(
            signerKeyPem: $this->leafKeyPem,
            responderId: DerEncoder::explicit(2, DerEncoder::octetString($this->leafKeyHash)),
            certStatus: DerEncoder::tlv(0x80, ''),
            includeCertPem: $this->leafNoAiaPem,
        );

        self::assertSame(RevocationStatus::Unknown, $this->check($response));
    }

    #[Test]
    public function returnsUnknownWhenTheLeafHasNoOcspResponderUrl(): void
    {
        $checker = new OcspRevocationChecker($this->httpReturning(''));

        self::assertSame(RevocationStatus::Unknown, $checker->check($this->leafNoAiaPem, $this->caPem));
    }

    #[Test]
    public function returnsUnknownWhenTheResponderIsUnreachable(): void
    {
        $client = $this->createStub(HttpClientInterface::class);
        $client->method('post')->willReturn(HttpResponse::fromRaw(503, [], ''));
        $checker = new OcspRevocationChecker($client);

        self::assertSame(RevocationStatus::Unknown, $checker->check($this->leafPem, $this->caPem));
    }

    private function check(string $responseDer): RevocationStatus
    {
        return new OcspRevocationChecker($this->httpReturning($responseDer))
            ->check($this->leafPem, $this->caPem);
    }

    private function httpReturning(string $body): HttpClientInterface
    {
        $client = $this->createStub(HttpClientInterface::class);
        $client->method('post')->willReturn(HttpResponse::fromRaw(200, [], $body));

        return $client;
    }

    private function ocspResponse(
        string $signerKeyPem,
        string $responderId,
        string $certStatus,
        bool $tamper = false,
        ?string $echoNonce = null,
        ?string $includeCertPem = null,
        int $thisUpdateOffset = -3600,
        int $nextUpdateOffset = 3600,
    ): string {
        $now = time();

        $single = DerEncoder::sequence(
            $this->certIdDer,
            $certStatus,
            DerEncoder::tlv(0x18, gmdate('YmdHis', $now + $thisUpdateOffset) . 'Z'),
            DerEncoder::explicit(0, DerEncoder::tlv(0x18, gmdate('YmdHis', $now + $nextUpdateOffset) . 'Z')),
        );

        $responseExtensions = $echoNonce === null
            ? ''
            : DerEncoder::explicit(1, DerEncoder::sequence(DerEncoder::sequence(
                DerEncoder::oid(self::OID_OCSP_NONCE),
                DerEncoder::octetString(DerEncoder::octetString($echoNonce)),
            )));

        $tbs = DerEncoder::sequence(
            $responderId,
            DerEncoder::tlv(0x18, gmdate('YmdHis', $now) . 'Z'),
            DerEncoder::sequence($single),
            $responseExtensions,
        );

        $signature = '';
        openssl_sign($tbs, $signature, $signerKeyPem, OPENSSL_ALGO_SHA256);

        if ($tamper) {
            $signature[0] = chr(ord($signature[0]) ^ 0xFF);
        }

        $certs = $includeCertPem === null
            ? ''
            : DerEncoder::explicit(0, DerEncoder::sequence($this->pemToDer($includeCertPem)));

        $basic = DerEncoder::sequence(
            $tbs,
            DerEncoder::algorithmIdentifier(self::OID_SHA256_RSA),
            DerEncoder::tlv(0x03, "\x00" . $signature),
            $certs,
        );

        return DerEncoder::sequence(
            DerEncoder::tlv(0x0A, "\x00"),
            DerEncoder::explicit(0, DerEncoder::sequence(
                DerEncoder::oid(self::OID_OCSP_BASIC),
                DerEncoder::octetString($basic),
            )),
        );
    }

    /**
     * @param array{config: string, digest_alg: string} $opts
     *
     * @return array{0: string, 1: string, 2: OpenSSLCertificate, 3: OpenSSLAsymmetricKey}
     */
    private function selfSignedCa(array $opts): array
    {
        $key = openssl_pkey_new(['config' => $opts['config'], 'private_key_bits' => 2048]);
        self::assertInstanceOf(OpenSSLAsymmetricKey::class, $key);
        $csr = openssl_csr_new(['commonName' => 'Fixture CA'], $key, $opts);
        self::assertNotFalse($csr);
        $cert = openssl_csr_sign($csr, null, $key, 3650, $opts + ['x509_extensions' => 'ca_ext'], 1000);
        self::assertInstanceOf(OpenSSLCertificate::class, $cert);

        $pem = '';
        self::assertTrue(openssl_x509_export($cert, $pem));
        $keyPem = '';
        self::assertTrue(openssl_pkey_export($key, $keyPem, null, ['config' => $opts['config']]));

        return [$pem, $keyPem, $cert, $key];
    }

    /**
     * @param array{config: string, digest_alg: string} $opts
     *
     * @return array{0: string, 1: string}
     */
    private function childCert(OpenSSLCertificate $caCert, OpenSSLAsymmetricKey $caKey, string $section, int $serial, array $opts): array
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

    private function pemToDer(string $pem): string
    {
        $body = preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $pem);

        return base64_decode((string) $body, true) ?: '';
    }
}
