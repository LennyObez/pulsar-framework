<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Internal\Certificate\Revocation;

use DateTimeImmutable;
use DateTimeZone;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Psd2\Internal\Certificate\Asn1\DerDecoder;
use Pulsar\Extension\Psd2\Internal\Certificate\Asn1\DerNode;
use Pulsar\Extension\Psd2\Internal\Certificate\CertificateFields;
use Pulsar\Http\Client\HttpClientInterface;
use Throwable;

use function base64_decode;
use function hash_equals;
use function is_string;
use function ltrim;
use function openssl_pkey_get_public;
use function openssl_verify;
use function preg_replace;
use function str_contains;
use function strlen;
use function substr;
use function time;

use const OPENSSL_ALGO_SHA1;
use const OPENSSL_ALGO_SHA256;
use const OPENSSL_ALGO_SHA384;
use const OPENSSL_ALGO_SHA512;

/**
 * Self-contained CRL revocation checker (RFC 5280), the fallback for issuers that
 * publish a CRL distribution point but no OCSP responder.
 *
 * It downloads the CRL named in the leaf's CRL Distribution Points extension
 * (through the framework's SSRF-protected HTTP client), verifies the CRL's
 * signature against the issuing CA, confirms the CRL is current (thisUpdate /
 * nextUpdate), then reports whether the leaf's serial appears in
 * revokedCertificates.
 *
 * Like {@see OcspRevocationChecker}, any failure to reach a cryptographically
 * verified verdict returns {@see RevocationStatus::Unknown} — never a silent
 * "good". The caller applies the fail-open/fail-closed policy.
 */
#[Internal(reason: 'Self-contained CRL revocation client for PSD2/eIDAS certificate validation')]
final readonly class CrlRevocationChecker implements RevocationCheckerInterface
{
    /** Cap the CRL download so a hostile or huge list cannot exhaust memory. */
    private const int MAX_CRL_BYTES = 5_242_880; // 5 MiB

    /** Signature AlgorithmIdentifier OID → OpenSSL digest constant. */
    private const array SIGNATURE_ALGORITHMS = [
        '1.2.840.113549.1.1.5' => OPENSSL_ALGO_SHA1,
        '1.2.840.113549.1.1.11' => OPENSSL_ALGO_SHA256,
        '1.2.840.113549.1.1.12' => OPENSSL_ALGO_SHA384,
        '1.2.840.113549.1.1.13' => OPENSSL_ALGO_SHA512,
        '1.2.840.10045.4.3.2' => OPENSSL_ALGO_SHA256,
        '1.2.840.10045.4.3.3' => OPENSSL_ALGO_SHA384,
        '1.2.840.10045.4.3.4' => OPENSSL_ALGO_SHA512,
    ];

    public function __construct(
        private HttpClientInterface $httpClient,
        private int $timeoutSeconds = 5,
        private int $clockSkewSeconds = 300,
    ) {}

    #[Override]
    public function check(string $leafPem, string $issuerPem): RevocationStatus
    {
        $leaf = CertificateFields::fromPem($leafPem);
        $issuer = CertificateFields::fromPem($issuerPem);

        if ($leaf === null || $issuer === null) {
            return RevocationStatus::Unknown;
        }

        $urls = $leaf->crlDistributionUrls();

        if ($urls === []) {
            return RevocationStatus::Unknown;
        }

        foreach ($urls as $url) {
            if (!str_contains($url, '://')) {
                continue; // skip non-HTTP distribution points (LDAP, etc.)
            }

            $der = $this->fetch($url);

            if ($der === null) {
                continue; // try the next distribution point
            }

            try {
                $status = $this->interpret($der, $leaf, $issuerPem);
            } catch (Throwable) {
                continue;
            }

            if ($status !== RevocationStatus::Unknown) {
                return $status;
            }
        }

        return RevocationStatus::Unknown;
    }

    private function fetch(string $url): ?string
    {
        try {
            $response = $this->httpClient->get($url, [
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

        if ($body === '' || strlen($body) > self::MAX_CRL_BYTES) {
            return null;
        }

        // CRLs are usually DER; tolerate a PEM-armoured body too.
        if (str_contains($body, '-----BEGIN')) {
            $base64 = preg_replace('/-----(BEGIN|END)[^-]*-----|\s+/', '', $body);
            $decoded = is_string($base64) ? base64_decode($base64, true) : false;

            return $decoded === false || $decoded === '' ? null : $decoded;
        }

        return $body;
    }

    private function interpret(string $der, CertificateFields $leaf, string $issuerPem): RevocationStatus
    {
        $certificateList = DerDecoder::decode($der);

        // CertificateList ::= SEQUENCE { tbsCertList, signatureAlgorithm, signatureValue BIT STRING }
        $tbs = $certificateList->child(0);
        $signatureAlgorithm = $certificateList->child(1);
        $signatureBits = $certificateList->child(2);

        if ($tbs === null || $signatureAlgorithm === null || $signatureBits === null) {
            return RevocationStatus::Unknown;
        }

        $algorithm = self::SIGNATURE_ALGORITHMS[DerDecoder::oidToString($signatureAlgorithm->child(0)?->content ?? '')] ?? null;

        if ($algorithm === null || !$this->verifySignature($tbs->raw, substr($signatureBits->content, 1), $issuerPem, $algorithm)) {
            return RevocationStatus::Unknown;
        }

        // TBSCertList ::= SEQUENCE { version?, signature, issuer, thisUpdate,
        //   nextUpdate?, revokedCertificates?, [0] crlExtensions? }
        // Skip the optional leading version INTEGER before signature/issuer/thisUpdate.
        $base = $tbs->child(0)?->tagNumber === DerNode::TAG_INTEGER ? 1 : 0;

        if (!$this->isFresh($tbs, $base)) {
            return RevocationStatus::Unknown;
        }

        $revoked = $this->revokedList($tbs, $base);

        if ($revoked === null) {
            // A verified, current CRL with no revoked-list means nothing is revoked.
            return RevocationStatus::Good;
        }

        $serial = $leaf->serialNumberBytes();

        foreach ($revoked->children as $entry) {
            if (hash_equals($serial, $entry->child(0)?->content ?? '')) {
                return RevocationStatus::Revoked;
            }
        }

        return RevocationStatus::Good;
    }

    /**
     * Locate the revokedCertificates SEQUENCE, or null when the CRL lists no
     * revoked certificates.
     *
     * TBSCertList layout past the optional version: signature (base), issuer
     * (base+1), thisUpdate (base+2), then optionally nextUpdate (a Time) and
     * revokedCertificates (a SEQUENCE). Scanning from base+3 for the first
     * universal SEQUENCE skips a nextUpdate Time and lands on the revoked list.
     */
    private function revokedList(DerNode $tbs, int $base): ?DerNode
    {
        for ($i = $base + 3, $count = $tbs->childCount(); $i < $count; $i++) {
            $child = $tbs->child($i);

            if ($child !== null && $child->isSequence()) {
                return $child;
            }
        }

        return null;
    }

    private function isFresh(DerNode $tbs, int $base): bool
    {
        // thisUpdate is at base+2 (after signature and issuer).
        $thisUpdate = $this->parseTime($tbs->child($base + 2));

        if ($thisUpdate === null) {
            return false;
        }

        $now = time();

        if ($thisUpdate > $now + $this->clockSkewSeconds) {
            return false;
        }

        // nextUpdate, when present, is the Time immediately after thisUpdate.
        $nextUpdate = $this->parseTime($tbs->child($base + 3));

        if ($nextUpdate !== null && $now > $nextUpdate + $this->clockSkewSeconds) {
            return false; // stale CRL — cannot be trusted
        }

        return true;
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
     * Parse an X.509 Time (UTCTime `YYMMDDHHMMSSZ` or GeneralizedTime
     * `YYYYMMDDHHMMSSZ`) to a Unix timestamp, or null for anything else.
     */
    private function parseTime(?DerNode $node): ?int
    {
        if ($node === null) {
            return null;
        }

        $format = match ($node->tagNumber) {
            DerNode::TAG_UTC_TIME => 'ymdHis\Z',
            DerNode::TAG_GENERALIZED_TIME => 'YmdHis\Z',
            default => null,
        };

        if ($format === null) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat($format, ltrim($node->content), new DateTimeZone('UTC'));

        return $parsed === false ? null : $parsed->getTimestamp();
    }
}
