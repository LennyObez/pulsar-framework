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
 */
#[Internal]
final class DeviceProofVerifier
{
    private const string CHALLENGE_SEPARATOR = '|';

    /**
     * Maximum age for a challenge in seconds before it expires.
     */
    private const int CHALLENGE_MAX_AGE = 300; // 5 minutes

    /**
     * Consumed nonces mapped to the wall-clock time at which they can no
     * longer be replayed (challenge timestamp + max age). Entries are pruned
     * once that time passes: the freshness check already rejects challenges
     * older than the window, so dropping an expired nonce cannot re-enable a
     * replay. This bounds memory to nonces consumed within one window rather
     * than letting the cache grow for the whole worker lifetime.
     *
     * @var array<string, int>
     */
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

        $now = time();

        // Check challenge freshness (replay safety - Finding D)
        $challengeAge = $now - (int) $timestamp;

        if ($challengeAge < 0 || $challengeAge > $this->challengeMaxAge) {
            return DeviceProofResult::failed('Challenge expired');
        }

        // Drop nonces that can no longer be replayed (their challenge would
        // now fail the freshness check) so the cache stays bounded.
        $this->pruneExpiredNonces($now);

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

        // Mark nonce as used until its challenge would expire on its own.
        $this->usedNonces[$nonce] = (int) $timestamp + $this->challengeMaxAge;

        return DeviceProofResult::verified($deviceId);
    }

    /**
     * Remove consumed nonces whose challenge window has elapsed.
     */
    private function pruneExpiredNonces(int $now): void
    {
        foreach ($this->usedNonces as $nonce => $expiresAt) {
            if ($expiresAt < $now) {
                unset($this->usedNonces[$nonce]);
            }
        }
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
