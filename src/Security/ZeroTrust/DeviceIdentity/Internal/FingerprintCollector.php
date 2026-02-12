<?php

declare(strict_types=1);

namespace Pulsar\Security\ZeroTrust\DeviceIdentity\Internal;

use Pulsar\Api\Internal;
use Pulsar\Security\Crypto\Hmac;
use Pulsar\Security\Crypto\KeyRingInterface;
use Pulsar\Security\ZeroTrust\DeviceIdentity\DeviceProofResult;

use function implode;
use function strtolower;
use function trim;

/**
 * Collects browser fingerprint components from request headers.
 *
 * Produces a fingerprint hash for weak-signal use only.
 * Fingerprint data is NEVER stored persistently -- it is evaluated and discarded.
 * Confidence is capped at 0.3 because fingerprints are easily spoofed.
 *
 * All hashing via KeyRingInterface (Finding B).
 */
#[Internal]
final readonly class FingerprintCollector
{
    /** Maximum confidence for fingerprint-based signals. */
    private const float MAX_CONFIDENCE = 0.3;

    /** Headers used as fingerprint components. */
    private const array FINGERPRINT_HEADERS = [
        'User-Agent',
        'Accept',
        'Accept-Language',
        'Accept-Encoding',
    ];

    public function __construct(
        private KeyRingInterface $keyRing,
        private string $keyId = 'device-fingerprint',
    ) {}

    /**
     * Collect fingerprint components from request headers and produce a hashed fingerprint.
     *
     * @param array<string, string> $headers Associative array of header name => value
     * @return DeviceProofResult Result with fingerprint hash as deviceId, capped at 0.3 confidence
     */
    public function collect(array $headers): DeviceProofResult
    {
        $key = $this->keyRing->keyFor($this->keyId);

        if ($key === null) {
            return DeviceProofResult::failed('Fingerprint signing key unavailable');
        }

        $components = $this->extractComponents($headers);

        if ($components === []) {
            return DeviceProofResult::failed('No fingerprint components available');
        }

        $rawFingerprint = implode('|', $components);
        $fingerprintHash = Hmac::computeHex($rawFingerprint, $key);

        return DeviceProofResult::verified($fingerprintHash, self::MAX_CONFIDENCE);
    }

    /**
     * Extract normalized fingerprint components from headers.
     *
     * @param array<string, string> $headers
     * @return list<string>
     */
    private function extractComponents(array $headers): array
    {
        $components = [];
        $normalizedHeaders = $this->normalizeHeaders($headers);

        foreach (self::FINGERPRINT_HEADERS as $header) {
            $key = strtolower($header);
            $value = $normalizedHeaders[$key] ?? '';
            $trimmed = trim($value);

            if ($trimmed !== '') {
                $components[] = $key . ':' . $trimmed;
            }
        }

        return $components;
    }

    /**
     * Normalize header names to lowercase for case-insensitive lookup.
     *
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)] = $value;
        }

        return $normalized;
    }
}
