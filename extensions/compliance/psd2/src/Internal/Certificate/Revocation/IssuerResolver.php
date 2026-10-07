<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Internal\Certificate\Revocation;

use Pulsar\Api\Internal;
use Pulsar\Extension\Psd2\Internal\Certificate\CertificateFields;

use function file_get_contents;
use function hash_equals;
use function is_file;
use function is_readable;
use function openssl_pkey_get_public;
use function openssl_x509_verify;
use function preg_match_all;

/**
 * Finds the certificate that issued a leaf, among the trusted CA bundle, so a
 * revocation check has the issuer it needs to build an OCSP CertID / verify a
 * CRL signature.
 *
 * A candidate is accepted only when its subject Name matches the leaf's issuer
 * Name AND its public key actually verifies the leaf's signature — matching the
 * name alone would let an unrelated CA of the same name stand in. Self-issued
 * certificates (trust anchors) resolve to null: a root is trusted directly and
 * is not itself OCSP/CRL-checked.
 */
#[Internal(reason: 'Resolves the issuing CA certificate for revocation checks')]
final readonly class IssuerResolver
{
    /**
     * @return string|null The issuer certificate PEM, or null when no separate
     *                      issuer can be established (self-signed, or absent).
     */
    public function resolve(string $leafPem, string $bundlePath): ?string
    {
        $leaf = CertificateFields::fromPem($leafPem);

        if ($leaf === null || $bundlePath === '' || !is_file($bundlePath) || !is_readable($bundlePath)) {
            return null;
        }

        // A self-signed / self-issued certificate is its own trust anchor.
        if ($this->selfIssued($leaf, $leafPem)) {
            return null;
        }

        $bundle = @file_get_contents($bundlePath);

        if ($bundle === false || $bundle === '') {
            return null;
        }

        $issuerNameDer = $leaf->issuerNameDer();

        foreach ($this->splitPems($bundle) as $candidatePem) {
            $candidate = CertificateFields::fromPem($candidatePem);

            if ($candidate === null || !hash_equals($issuerNameDer, $candidate->subjectNameDer())) {
                continue;
            }

            $candidateKey = openssl_pkey_get_public($candidatePem);

            if ($candidateKey !== false && openssl_x509_verify($leafPem, $candidateKey) === 1) {
                return $candidatePem;
            }
        }

        return null;
    }

    private function selfIssued(CertificateFields $leaf, string $leafPem): bool
    {
        if (!hash_equals($leaf->issuerNameDer(), $leaf->subjectNameDer())) {
            return false;
        }

        $key = openssl_pkey_get_public($leafPem);

        return $key !== false && openssl_x509_verify($leafPem, $key) === 1;
    }

    /**
     * @return list<string>
     */
    private function splitPems(string $bundle): array
    {
        if (preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $bundle, $matches) === false) {
            return [];
        }

        return $matches[0];
    }
}
