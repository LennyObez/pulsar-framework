<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Internal\Certificate\Revocation;

use DateTimeImmutable;
use DateTimeZone;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Psd2\Internal\Certificate\Asn1\DerDecoder;
use Pulsar\Extension\Psd2\Internal\Certificate\Asn1\DerEncoder;
use Pulsar\Extension\Psd2\Internal\Certificate\Asn1\DerNode;
use Pulsar\Extension\Psd2\Internal\Certificate\CertificateFields;
use Pulsar\Http\Client\HttpClientInterface;
use Throwable;

use function base64_encode;
use function chunk_split;
use function hash_equals;
use function openssl_pkey_get_public;
use function openssl_verify;
use function openssl_x509_verify;
use function ord;
use function random_bytes;
use function sha1;
use function substr;
use function time;

use const OPENSSL_ALGO_SHA1;
use const OPENSSL_ALGO_SHA256;
use const OPENSSL_ALGO_SHA384;
use const OPENSSL_ALGO_SHA512;

/**
 * Self-contained OCSP client (RFC 6960 / RFC 8954) with no dependency on the
 * openssl command line.
 *
 * It builds a DER OCSP request for the leaf certificate, POSTs it to the
 * responder named in the leaf's Authority Information Access extension, then
 * fully validates the BasicOCSPResponse before trusting its verdict:
 *
 *  1. the response signature verifies against either the issuing CA or a
 *     delegated responder whose certificate is (a) signed by that CA and
 *     (b) carries the id-kp-OCSPSigning extended key usage (§4.2.2.2);
 *  2. the nonce, when echoed, matches the one we sent (replay protection);
 *  3. the SingleResponse whose CertID matches our request is within its
 *     thisUpdate / nextUpdate validity window.
 *
 * Any failure to reach a *cryptographically verified* Good/Revoked verdict
 * returns {@see RevocationStatus::Unknown} — never a silent "good". The caller
 * decides whether Unknown is tolerated (soft-fail) by policy.
 */
#[Internal(reason: 'Self-contained OCSP revocation client for PSD2/eIDAS certificate validation')]
final readonly class OcspRevocationChecker implements RevocationCheckerInterface
{
    private const string OID_SHA1 = '1.3.14.3.2.26';
    private const string OID_OCSP_NONCE = '1.3.6.1.5.5.7.48.1.2';
    private const string OID_OCSP_BASIC = '1.3.6.1.5.5.7.48.1.1';
    private const string OID_OCSP_SIGNING = '1.3.6.1.5.5.7.3.9';

    private const int OCSP_STATUS_SUCCESSFUL = 0;

    /** Signature AlgorithmIdentifier OID → OpenSSL digest constant. */
    private const array SIGNATURE_ALGORITHMS = [
        '1.2.840.113549.1.1.5' => OPENSSL_ALGO_SHA1,    // sha1WithRSAEncryption
        '1.2.840.113549.1.1.11' => OPENSSL_ALGO_SHA256, // sha256WithRSAEncryption
        '1.2.840.113549.1.1.12' => OPENSSL_ALGO_SHA384, // sha384WithRSAEncryption
        '1.2.840.113549.1.1.13' => OPENSSL_ALGO_SHA512, // sha512WithRSAEncryption
        '1.2.840.10045.4.3.2' => OPENSSL_ALGO_SHA256,   // ecdsa-with-SHA256
        '1.2.840.10045.4.3.3' => OPENSSL_ALGO_SHA384,   // ecdsa-with-SHA384
        '1.2.840.10045.4.3.4' => OPENSSL_ALGO_SHA512,   // ecdsa-with-SHA512
    ];

    public function __construct(
        private HttpClientInterface $httpClient,
        private int $timeoutSeconds = 5,
        private int $clockSkewSeconds = 300,
    ) {}

    /**
     * Resolve the revocation status of $leafPem, issued by $issuerPem, via OCSP.
     */
    #[Override]
    public function check(string $leafPem, string $issuerPem): RevocationStatus
    {
        $leaf = CertificateFields::fromPem($leafPem);
        $issuer = CertificateFields::fromPem($issuerPem);

        if ($leaf === null || $issuer === null) {
            return RevocationStatus::Unknown;
        }

        $url = $leaf->ocspResponderUrl();

        if ($url === null || $url === '') {
            return RevocationStatus::Unknown;
        }

        $nonce = random_bytes(16);
        $requestDer = $this->buildRequest($leaf, $issuer, $nonce);
        $responseDer = $this->fetch($url, $requestDer);

        if ($responseDer === null) {
            return RevocationStatus::Unknown;
        }

        try {
            return $this->interpret($responseDer, $leaf, $issuer, $issuerPem, $nonce);
        } catch (Throwable) {
            return RevocationStatus::Unknown;
        }
    }

    private function buildRequest(CertificateFields $leaf, CertificateFields $issuer, string $nonce): string
    {
        $certId = DerEncoder::sequence(
            DerEncoder::algorithmIdentifier(self::OID_SHA1),
            DerEncoder::octetString(sha1($leaf->issuerNameDer(), true)),
            DerEncoder::octetString($issuer->subjectPublicKeyHash()),
            DerEncoder::integerFromBytes($leaf->serialNumberBytes()),
        );

        $requestList = DerEncoder::sequence(DerEncoder::sequence($certId));

        // Nonce extension: extnValue is an OCTET STRING wrapping the nonce OCTET STRING.
        $nonceExtension = DerEncoder::sequence(
            DerEncoder::oid(self::OID_OCSP_NONCE),
            DerEncoder::octetString(DerEncoder::octetString($nonce)),
        );

        $tbsRequest = DerEncoder::sequence(
            $requestList,
            DerEncoder::explicit(2, DerEncoder::sequence($nonceExtension)),
        );

        return DerEncoder::sequence($tbsRequest);
    }

    private function fetch(string $url, string $requestDer): ?string
    {
        try {
            $response = $this->httpClient->post($url, [
                'body' => $requestDer,
                'headers' => [
                    'Content-Type' => 'application/ocsp-request',
                    'Accept' => 'application/ocsp-response',
                ],
                'timeout' => $this->timeoutSeconds,
                'retries' => 0,
            ]);
        } catch (Throwable) {
            return null;
        }

        if ($response->status() !== 200) {
            return null;
        }

        $body = $response->body();

        return $body === '' ? null : $body;
    }

    private function interpret(
        string $responseDer,
        CertificateFields $leaf,
        CertificateFields $issuer,
        string $issuerPem,
        string $nonce,
    ): RevocationStatus {
        $response = DerDecoder::decode($responseDer);

        // OCSPResponse ::= SEQUENCE { responseStatus ENUMERATED, [0] responseBytes OPTIONAL }
        $statusNode = $response->child(0);

        if ($statusNode === null || $statusNode->content === '' || ord($statusNode->content[0]) !== self::OCSP_STATUS_SUCCESSFUL) {
            return RevocationStatus::Unknown;
        }

        $responseBytesWrapper = $response->child(1);
        $responseBytes = $responseBytesWrapper?->child(0);

        if ($responseBytesWrapper === null || !$responseBytesWrapper->isContextTag(0) || $responseBytes === null) {
            return RevocationStatus::Unknown;
        }

        // ResponseBytes ::= SEQUENCE { responseType OID, response OCTET STRING }
        $responseType = $responseBytes->child(0);
        $responsePayload = $responseBytes->child(1);

        if ($responseType === null || $responsePayload === null
            || DerDecoder::oidToString($responseType->content) !== self::OID_OCSP_BASIC) {
            return RevocationStatus::Unknown;
        }

        $basic = DerDecoder::decode($responsePayload->content);

        // BasicOCSPResponse ::= SEQUENCE { tbsResponseData, signatureAlgorithm, signature BIT STRING, [0] certs OPTIONAL }
        $tbs = $basic->child(0);
        $signatureAlgorithm = $basic->child(1);
        $signatureBits = $basic->child(2);

        if ($tbs === null || $signatureAlgorithm === null || $signatureBits === null) {
            return RevocationStatus::Unknown;
        }

        $algorithm = self::SIGNATURE_ALGORITHMS[DerDecoder::oidToString($signatureAlgorithm->child(0)->content ?? '')] ?? null;

        if ($algorithm === null) {
            return RevocationStatus::Unknown;
        }

        $responderId = $this->responderId($tbs);

        if ($responderId === null) {
            return RevocationStatus::Unknown;
        }

        $signerPem = $this->resolveSigner($responderId, $basic->child(3), $issuer, $issuerPem);

        if ($signerPem === null) {
            return RevocationStatus::Unknown;
        }

        if (!$this->verifySignature($tbs->raw, substr($signatureBits->content, 1), $signerPem, $algorithm)) {
            return RevocationStatus::Unknown;
        }

        if (!$this->nonceMatches($tbs, $nonce)) {
            return RevocationStatus::Unknown;
        }

        return $this->readStatus($tbs, $leaf, $issuer);
    }

    /**
     * ResponderID is the first context-tagged child of ResponseData, appearing
     * before producedAt (skipping the optional [0] version).
     */
    private function responderId(DerNode $tbs): ?DerNode
    {
        foreach ($tbs->children as $child) {
            if ($child->isContextTag(0)) {
                continue; // version
            }

            if ($child->tagClass === DerNode::CLASS_CONTEXT) {
                return $child; // byName [1] or byKey [2]
            }

            return null;
        }

        return null;
    }

    private function resolveSigner(DerNode $responderId, ?DerNode $certsWrapper, CertificateFields $issuer, string $issuerPem): ?string
    {
        if ($this->responderMatchesIssuer($responderId, $issuer)) {
            return $issuerPem;
        }

        // Delegated responder: a certificate carried in the response, signed by
        // the issuing CA and bearing the OCSP-signing EKU.
        $certificates = $certsWrapper?->isContextTag(0) === true ? $certsWrapper->child(0) : null;

        if ($certificates === null) {
            return null;
        }

        $issuerKey = openssl_pkey_get_public($issuerPem);

        if ($issuerKey === false) {
            return null;
        }

        foreach ($certificates->children as $certificate) {
            $pem = $this->derToPem($certificate->raw);

            if (openssl_x509_verify($pem, $issuerKey) !== 1) {
                continue;
            }

            $fields = CertificateFields::fromPem($pem);

            if ($fields === null || !$fields->hasExtendedKeyUsage(self::OID_OCSP_SIGNING)) {
                continue;
            }

            if ($this->certificateMatchesResponder($fields, $responderId)) {
                return $pem;
            }
        }

        return null;
    }

    private function responderMatchesIssuer(DerNode $responderId, CertificateFields $issuer): bool
    {
        if ($responderId->isContextTag(2)) {
            $keyHash = $responderId->child(0)->content ?? '';

            return $keyHash !== '' && hash_equals($issuer->subjectPublicKeyHash(), $keyHash);
        }

        if ($responderId->isContextTag(1)) {
            $nameDer = $responderId->child(0)->raw ?? '';

            return $nameDer !== '' && hash_equals($issuer->subjectNameDer(), $nameDer);
        }

        return false;
    }

    private function certificateMatchesResponder(CertificateFields $fields, DerNode $responderId): bool
    {
        if ($responderId->isContextTag(2)) {
            return hash_equals($fields->subjectPublicKeyHash(), $responderId->child(0)->content ?? '');
        }

        if ($responderId->isContextTag(1)) {
            return hash_equals($fields->subjectNameDer(), $responderId->child(0)->raw ?? '');
        }

        return false;
    }

    private function verifySignature(string $signedData, string $signature, string $signerPem, int $algorithm): bool
    {
        $key = openssl_pkey_get_public($signerPem);

        if ($key === false) {
            return false;
        }

        return openssl_verify($signedData, $signature, $key, $algorithm) === 1;
    }

    /**
     * If the response echoes a nonce it MUST equal the one we sent; a missing
     * nonce is tolerated (RFC 5019 cached responses) and left to freshness.
     */
    private function nonceMatches(DerNode $tbs, string $nonce): bool
    {
        $extensions = null;

        foreach ($tbs->children as $child) {
            if ($child->isContextTag(1)) {
                $extensions = $child->child(0);

                break;
            }
        }

        if ($extensions === null) {
            return true;
        }

        foreach ($extensions->children as $extension) {
            $idNode = $extension->child(0);

            if ($idNode === null || DerDecoder::oidToString($idNode->content) !== self::OID_OCSP_NONCE) {
                continue;
            }

            $extnValue = $extension->child($extension->childCount() - 1)->content ?? '';

            try {
                $echoed = DerDecoder::decode($extnValue)->content;
            } catch (Throwable) {
                return false;
            }

            return hash_equals($nonce, $echoed);
        }

        return true;
    }

    private function readStatus(DerNode $tbs, CertificateFields $leaf, CertificateFields $issuer): RevocationStatus
    {
        $responses = $this->singleResponses($tbs);

        if ($responses === null) {
            return RevocationStatus::Unknown;
        }

        $expectedNameHash = sha1($leaf->issuerNameDer(), true);
        $expectedKeyHash = $issuer->subjectPublicKeyHash();
        $expectedSerial = $leaf->serialNumberBytes();

        foreach ($responses->children as $single) {
            $certId = $single->child(0);
            $certStatus = $single->child(1);

            if ($certId === null || $certStatus === null) {
                continue;
            }

            if (!hash_equals($expectedNameHash, $certId->child(1)->content ?? '')
                || !hash_equals($expectedKeyHash, $certId->child(2)->content ?? '')
                || !hash_equals($expectedSerial, $certId->child(3)->content ?? '')) {
                continue;
            }

            if (!$this->isFresh($single)) {
                return RevocationStatus::Unknown;
            }

            return match ($certStatus->tagNumber) {
                0 => RevocationStatus::Good,
                1 => RevocationStatus::Revoked,
                default => RevocationStatus::Unknown,
            };
        }

        return RevocationStatus::Unknown;
    }

    /**
     * The SEQUENCE OF SingleResponse: the only universal SEQUENCE child of
     * ResponseData (version is [0], responderID is [1]/[2], producedAt is a
     * GeneralizedTime, responseExtensions is [1]).
     */
    private function singleResponses(DerNode $tbs): ?DerNode
    {
        foreach ($tbs->children as $child) {
            if ($child->isSequence()) {
                return $child;
            }
        }

        return null;
    }

    private function isFresh(DerNode $single): bool
    {
        $thisUpdate = $this->parseTime($single->child(2)->content ?? '');

        if ($thisUpdate === null) {
            return false;
        }

        $now = time();

        if ($thisUpdate > $now + $this->clockSkewSeconds) {
            return false; // not yet valid
        }

        $nextUpdateNode = $single->child(3);

        if ($nextUpdateNode !== null && $nextUpdateNode->isContextTag(0)) {
            $nextUpdate = $this->parseTime($nextUpdateNode->child(0)->content ?? '');

            if ($nextUpdate !== null && $now > $nextUpdate + $this->clockSkewSeconds) {
                return false; // stale
            }
        }

        return true;
    }

    private function parseTime(string $generalizedTime): ?int
    {
        if ($generalizedTime === '') {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('YmdHis\Z', $generalizedTime, new DateTimeZone('UTC'));

        return $parsed === false ? null : $parsed->getTimestamp();
    }

    private function derToPem(string $der): string
    {
        return "-----BEGIN CERTIFICATE-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END CERTIFICATE-----\n";
    }
}
