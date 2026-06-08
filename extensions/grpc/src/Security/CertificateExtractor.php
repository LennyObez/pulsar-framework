<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Security;

use Pulsar\Api\Internal;

use function is_array;
use function is_string;
use function openssl_x509_parse;
use function str_starts_with;
use function substr;
use function trim;

/**
 * Extracts Subject Alternative Name (SAN) from X.509 client certificates.
 *
 * Structured data extraction only: no dynamic interpretation.
 * Uses openssl_x509_parse() for certificate parsing.
 */
#[Internal(reason: 'Certificate parsing utility')]
final readonly class CertificateExtractor
{
    /**
     * Extract the first DNS SAN from a PEM-encoded X.509 certificate.
     *
     * @param string $certPem PEM-encoded certificate data
     *
     * @return string|null The first DNS SAN, or null if parsing fails or no DNS SAN exists
     */
    public function extractSan(string $certPem): ?string
    {
        $certPem = trim($certPem);

        if ($certPem === '') {
            return null;
        }

        $parsed = openssl_x509_parse($certPem);

        if ($parsed === false) {
            return null;
        }

        $extensions = $parsed['extensions'] ?? [];

        if (!is_array($extensions)) {
            return null;
        }

        $sanString = $extensions['subjectAltName'] ?? null;

        if (!is_string($sanString) || $sanString === '') {
            return null;
        }

        $parts = explode(',', $sanString);

        foreach ($parts as $part) {
            $part = trim($part);

            if (str_starts_with($part, 'DNS:')) {
                $dns = trim(substr($part, 4));

                if ($dns !== '') {
                    return $dns;
                }
            }
        }

        return null;
    }
}
