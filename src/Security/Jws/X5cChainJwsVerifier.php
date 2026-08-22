<?php

declare(strict_types=1);

namespace Pulsar\Security\Jws;

use DateTimeImmutable;
use JsonException;
use OpenSSLCertificate;
use Pulsar\Api\Internal;

use function array_filter;
use function array_values;
use function base64_decode;
use function chr;
use function chunk_split;
use function count;
use function explode;
use function hash_equals;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function ltrim;
use function openssl_pkey_get_public;
use function openssl_verify;
use function openssl_x509_fingerprint;
use function openssl_x509_parse;
use function openssl_x509_read;
use function ord;
use function str_pad;
use function strlen;
use function strtr;
use function substr;

use const JSON_THROW_ON_ERROR;
use const OPENSSL_ALGO_SHA256;
use const STR_PAD_RIGHT;

/**
 * Verifies an ES256 compact JWS whose header carries an x5c certificate chain,
 * pinned to one or more trusted root certificates.
 *
 * Used for Apple App Store Server API / Server Notifications v2 payloads, which
 * are signed with ECDSA P-256 and present a leaf → Apple WWDR intermediate →
 * Apple Root CA G3 chain in the JOSE `x5c` header. Trust derives ONLY from the
 * locally pinned root(s) passed to the constructor — never from the root the
 * message itself supplies — so a self-signed forgery cannot pass.
 *
 * The verification is: alg must be ES256 (no alg-confusion); every presented
 * certificate must be within its validity window; each chain link must be
 * cryptographically signed by the next; the top presented certificate must be
 * byte-identical to a pinned root or directly signed by one; and the JWS
 * signature must verify against the leaf's public key. The compact JWS signature
 * is RFC 7518 raw R||S and is converted to ASN.1 DER before openssl_verify.
 */
#[Internal]
final readonly class X5cChainJwsVerifier implements JwsVerifierInterface
{
    /**
     * @param non-empty-list<string> $trustedRootPems PEM-encoded trust anchors (e.g. Apple Root CA G3)
     */
    public function __construct(
        private array $trustedRootPems,
    ) {}

    public function verifyAndDecode(string $jws, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now');
        $timestamp = $now->getTimestamp();

        $parts = explode('.', $jws);
        if (count($parts) !== 3) {
            throw JwsVerificationException::fromReason('JWS must have exactly three segments');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        $header = $this->decodeJson($encodedHeader);

        if (($header['alg'] ?? null) !== 'ES256') {
            throw JwsVerificationException::fromReason('Unsupported JWS algorithm; only ES256 is accepted');
        }

        if (!isset($header['x5c']) || !is_array($header['x5c']) || $header['x5c'] === []) {
            throw JwsVerificationException::fromReason('JWS header carries no x5c certificate chain');
        }

        $entries = array_values(array_filter($header['x5c'], 'is_string'));
        if ($entries === [] || count($entries) !== count($header['x5c'])) {
            throw JwsVerificationException::fromReason('x5c contains a non-string entry');
        }

        /** @var list<OpenSSLCertificate> $certs */
        $certs = [];
        /** @var list<string> $pems */
        $pems = [];

        foreach ($entries as $entry) {
            if ($entry === '' || base64_decode($entry, true) === false) {
                throw JwsVerificationException::fromReason('x5c entry is not a valid base64 certificate');
            }

            $pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split($entry, 64, "\n") . "-----END CERTIFICATE-----\n";
            $cert = openssl_x509_read($pem);

            if ($cert === false) {
                throw JwsVerificationException::fromReason('x5c entry is not a valid certificate');
            }

            $certs[] = $cert;
            $pems[] = $pem;
        }

        $count = count($certs);

        $this->assertWithinValidity($certs, $timestamp);
        $this->assertChainLinks($certs, $pems, $count);

        if (!$this->topIsPinned($pems[$count - 1], $certs[$count - 1])) {
            throw JwsVerificationException::fromReason('Certificate chain does not anchor to a trusted root');
        }

        $this->assertLeafSignature($certs[0], $encodedHeader, $encodedPayload, $encodedSignature);

        return $this->decodeJson($encodedPayload);
    }

    /**
     * @param list<OpenSSLCertificate> $certs
     */
    private function assertWithinValidity(array $certs, int $timestamp): void
    {
        foreach ($certs as $cert) {
            $parsed = openssl_x509_parse($cert);

            if (
                !is_array($parsed)
                || !isset($parsed['validFrom_time_t'], $parsed['validTo_time_t'])
                || !is_int($parsed['validFrom_time_t'])
                || !is_int($parsed['validTo_time_t'])
            ) {
                throw JwsVerificationException::fromReason('Certificate validity window is unreadable');
            }

            if ($timestamp < $parsed['validFrom_time_t'] || $timestamp > $parsed['validTo_time_t']) {
                throw JwsVerificationException::fromReason('Certificate is outside its validity window');
            }
        }
    }

    /**
     * Every cert must be signed by the next one up (leaf ← intermediate ← …).
     *
     * @param list<OpenSSLCertificate> $certs
     * @param list<string>             $pems
     */
    private function assertChainLinks(array $certs, array $pems, int $count): void
    {
        for ($i = 0; $i < $count - 1; $i++) {
            $issuerPublicKey = openssl_pkey_get_public($certs[$i + 1]);

            if ($issuerPublicKey === false) {
                throw JwsVerificationException::fromReason('Issuer public key is unreadable');
            }

            if (openssl_x509_verify($pems[$i], $issuerPublicKey) !== 1) {
                throw JwsVerificationException::fromReason('Certificate chain link is not signed by its issuer');
            }
        }
    }

    /**
     * Accept only when the top presented cert IS a pinned root (byte-identical
     * fingerprint) or is directly signed by one (Apple may omit the self-signed
     * root from x5c). Never trust the root the message itself supplies.
     */
    private function topIsPinned(string $topPem, OpenSSLCertificate $topCert): bool
    {
        $topFingerprint = openssl_x509_fingerprint($topCert, 'sha256');

        foreach ($this->trustedRootPems as $rootPem) {
            $anchor = openssl_x509_read($rootPem);

            if ($anchor === false) {
                continue;
            }

            $anchorFingerprint = openssl_x509_fingerprint($anchor, 'sha256');

            if (
                is_string($topFingerprint)
                && is_string($anchorFingerprint)
                && hash_equals($anchorFingerprint, $topFingerprint)
            ) {
                return true;
            }

            $anchorPublicKey = openssl_pkey_get_public($anchor);

            if ($anchorPublicKey !== false && openssl_x509_verify($topPem, $anchorPublicKey) === 1) {
                return true;
            }
        }

        return false;
    }

    private function assertLeafSignature(
        OpenSSLCertificate $leaf,
        string $encodedHeader,
        string $encodedPayload,
        string $encodedSignature,
    ): void {
        $signature = $this->base64UrlDecode($encodedSignature);

        if (strlen($signature) !== 64) {
            throw JwsVerificationException::fromReason('ES256 signature must be 64 bytes');
        }

        $leafPublicKey = openssl_pkey_get_public($leaf);

        if ($leafPublicKey === false) {
            throw JwsVerificationException::fromReason('Leaf public key is unreadable');
        }

        $verified = openssl_verify(
            $encodedHeader . '.' . $encodedPayload,
            $this->joseToDer($signature),
            $leafPublicKey,
            OPENSSL_ALGO_SHA256,
        );

        if ($verified !== 1) {
            throw JwsVerificationException::fromReason('JWS signature does not verify against the leaf certificate');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $segment): array
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($this->base64UrlDecode($segment), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw JwsVerificationException::fromReason('JWS segment is not valid JSON: ' . $e->getMessage());
        }

        if (!is_array($decoded)) {
            throw JwsVerificationException::fromReason('JWS segment is not a JSON object');
        }

        /** @var array<string, mixed> */
        return $decoded;
    }

    private function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;

        if ($remainder !== 0) {
            $data = str_pad($data, strlen($data) + (4 - $remainder), '=', STR_PAD_RIGHT);
        }

        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        if ($decoded === false) {
            throw JwsVerificationException::fromReason('JWS segment is not valid base64url');
        }

        return $decoded;
    }

    /**
     * Convert a JOSE raw R||S ES256 signature (64 bytes) to ASN.1 DER, which is
     * what openssl_verify expects: SEQUENCE { INTEGER r, INTEGER s }.
     */
    private function joseToDer(string $signature): string
    {
        $r = ltrim(substr($signature, 0, 32), "\x00");
        $s = ltrim(substr($signature, 32), "\x00");

        if ($r === '') {
            $r = "\x00";
        }

        if ($s === '') {
            $s = "\x00";
        }

        // Keep each INTEGER positive: a leading high bit would read as negative.
        if ((ord($r[0]) & 0x80) !== 0) {
            $r = "\x00" . $r;
        }

        if ((ord($s[0]) & 0x80) !== 0) {
            $s = "\x00" . $s;
        }

        // r and s are ≤ 33 bytes for P-256, so every DER length fits a single
        // byte; mask to satisfy chr()'s 0-255 domain.
        $sequence = "\x02" . chr(strlen($r) & 0xFF) . $r . "\x02" . chr(strlen($s) & 0xFF) . $s;

        return "\x30" . chr(strlen($sequence) & 0xFF) . $sequence;
    }
}
