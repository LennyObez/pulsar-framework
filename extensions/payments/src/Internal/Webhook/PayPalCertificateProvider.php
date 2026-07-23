<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Internal\Webhook;

use Pulsar\Api\Internal;
use Pulsar\Extension\Payments\Contracts\PayPalCertificateProviderInterface;

use function file_get_contents;
use function is_array;
use function is_string;
use function openssl_pkey_get_details;
use function openssl_pkey_get_public;
use function parse_url;
use function str_ends_with;
use function stream_context_create;
use function time;

/**
 * Fetches the PayPal webhook signing certificate over TLS and returns its RSA
 * public key, restricted to PayPal-hosted certificate URLs.
 *
 * The certificate URL is attacker-supplied (a webhook header), so the host is
 * allow-listed to paypal.com and the scheme forced to https before any fetch —
 * this is the SSRF control. The download itself uses hostname-based TLS with
 * verify_peer (rather than the DNS-pinned HttpClient, whose IP pinning would
 * defeat certificate-name validation on the fetch). Results are cached
 * in-process by URL to avoid re-downloading on every webhook.
 */
#[Internal]
final class PayPalCertificateProvider implements PayPalCertificateProviderInterface
{
    private const int CACHE_TTL = 3600;
    private const float FETCH_TIMEOUT = 3.0;

    /**
     * @param list<string> $allowedHosts Certificate-URL hosts trusted to serve PayPal signing certs
     */
    public function __construct(
        private readonly array $allowedHosts = ['paypal.com'],
    ) {}

    /** @var array<string, array{key: string, expires: int}> */
    private array $cache = [];

    public function publicKeyPemFor(string $certUrl): ?string
    {
        if (!$this->isAllowedUrl($certUrl)) {
            return null;
        }

        if (isset($this->cache[$certUrl]) && $this->cache[$certUrl]['expires'] > time()) {
            return $this->cache[$certUrl]['key'];
        }

        $certificate = $this->fetch($certUrl);
        if ($certificate === null) {
            return null;
        }

        $publicKey = openssl_pkey_get_public($certificate);
        if ($publicKey === false) {
            return null;
        }

        $details = openssl_pkey_get_details($publicKey);
        if (!is_array($details) || !isset($details['key']) || !is_string($details['key'])) {
            return null;
        }

        $pem = $details['key'];
        $this->cache[$certUrl] = ['key' => $pem, 'expires' => time() + self::CACHE_TTL];

        return $pem;
    }

    private function isAllowedUrl(string $url): bool
    {
        $parsed = parse_url($url);

        if (!is_array($parsed) || ($parsed['scheme'] ?? '') !== 'https') {
            return false;
        }

        $host = $parsed['host'] ?? '';

        foreach ($this->allowedHosts as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return true;
            }
        }

        return false;
    }

    private function fetch(string $url): ?string
    {
        $context = stream_context_create([
            'http' => ['timeout' => self::FETCH_TIMEOUT],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $certificate = @file_get_contents($url, false, $context);

        return $certificate === false ? null : $certificate;
    }
}
