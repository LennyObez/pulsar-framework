<?php

declare(strict_types=1);

namespace Pulsar\Security\ZeroTrust\DeviceIdentity\Internal;

use Pulsar\Api\Internal;
use Pulsar\Security\Crypto\Hmac;
use Pulsar\Security\Crypto\KeyRingInterface;
use Pulsar\Security\ZeroTrust\DeviceIdentity\DeviceProofResult;

use function bin2hex;
use function count;
use function explode;
use function hash_equals;
use function random_bytes;
use function time;

/**
 * Verifies cryptographic device proofs using challenge-response with KeyRing-based signing.
 *
 * Challenges include a nonce and timestamp for replay safety (Finding D).
 * All crypto operations go through KeyRingInterface (Finding B).
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal]
final class DeviceProofVerifier
{
    private const string CHALLENGE_SEPARATOR = '|';

    /**
     * Maximum age for a challenge in seconds before it expires.
     */
    private const int CHALLENGE_MAX_AGE = 300; // 5 minutes

    /** @var array<string, string> Used nonces to prevent replay */
    private array $usedNonces = [];

    public function __construct(
        private readonly KeyRingInterface $keyRing,
        private readonly string $keyId = 'device-proof',
        private readonly int $challengeMaxAge = self::CHALLENGE_MAX_AGE,
    ) {}

    /**
     * Generate a challenge containing a nonce and timestamp for replay safety.
     *
     * @return string The challenge string (nonce|timestamp)
     */
    public function generateChallenge(): string
    {
        $nonce = bin2hex(random_bytes(32));
        $timestamp = (string) time();

        return $nonce . self::CHALLENGE_SEPARATOR . $timestamp;
    }

    /**
     * Verify a device proof against a challenge and public key.
     *
     * The proof is expected to be an HMAC of (challenge + publicKey) using the device-proof key.
     *
     * @param string $challenge The challenge that was issued
     * @param string $proof The signed proof from the device
     * @param string $publicKey The device's registered public key
     * @param string $deviceId The device identifier for the result
     */
    public function verify(string $challenge, string $proof, string $publicKey, string $deviceId): DeviceProofResult
    {
        // Validate challenge format
        $parts = explode(self::CHALLENGE_SEPARATOR, $challenge);

        if (count($parts) !== 2) {
            return DeviceProofResult::failed('Malformed challenge');
        }

        [$nonce, $timestamp] = $parts;

        // Check challenge freshness (replay safety - Finding D)
        $challengeAge = time() - (int) $timestamp;

        if ($challengeAge < 0 || $challengeAge > $this->challengeMaxAge) {
            return DeviceProofResult::failed('Challenge expired');
        }

        // Check nonce reuse (replay prevention)
        if (isset($this->usedNonces[$nonce])) {
            return DeviceProofResult::failed('Challenge already used (replay detected)');
        }

        $key = $this->keyRing->keyFor($this->keyId);

        if ($key === null) {
            return DeviceProofResult::failed('Verification key unavailable');
        }

        // Verify the proof
        $expectedProof = Hmac::computeHex($challenge . $publicKey, $key);

        if (!hash_equals($expectedProof, $proof)) {
            return DeviceProofResult::failed('Proof verification failed');
        }

        // Mark nonce as used
        $this->usedNonces[$nonce] = $nonce;

        return DeviceProofResult::verified($deviceId);
    }

    /**
     * Generate a valid proof for testing and internal use.
     *
     * @return string|null The proof, or null if the signing key is unavailable
     */
    public function sign(string $challenge, string $publicKey): ?string
    {
        $key = $this->keyRing->keyFor($this->keyId);

        if ($key === null) {
            return null;
        }

        return Hmac::computeHex($challenge . $publicKey, $key);
    }
}
