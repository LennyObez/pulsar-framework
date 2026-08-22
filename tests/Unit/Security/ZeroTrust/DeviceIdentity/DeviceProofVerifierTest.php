<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ZeroTrust\DeviceIdentity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Crypto\EnvKeyRing;
use Pulsar\Security\ZeroTrust\DeviceIdentity\DeviceProofResult;
use Pulsar\Security\ZeroTrust\DeviceIdentity\Internal\DeviceProofVerifier;

use function random_bytes;
use function str_repeat;
use function strlen;
use function time;

#[CoversClass(DeviceProofVerifier::class)]
#[CoversClass(DeviceProofResult::class)]
final class DeviceProofVerifierTest extends TestCase
{
    private DeviceProofVerifier $verifier;

    protected function setUp(): void
    {
        $key = random_bytes(32);
        $keyRing = new EnvKeyRing(['device-proof' => $key]);
        $this->verifier = new DeviceProofVerifier($keyRing);
    }

    // ── Challenge generation ───────────────────────────────────────────

    #[Test]
    public function generateChallengeReturnsNonEmptyString(): void
    {
        $challenge = $this->verifier->generateChallenge();

        self::assertNotEmpty($challenge);
        self::assertStringContainsString('|', $challenge);
    }

    #[Test]
    public function generateChallengeProducesUniqueValues(): void
    {
        $challenges = [];
        for ($i = 0; $i < 10; $i++) {
            $challenges[] = $this->verifier->generateChallenge();
        }

        self::assertCount(10, array_unique($challenges));
    }

    #[Test]
    public function challengeContainsNonceAndTimestamp(): void
    {
        $challenge = $this->verifier->generateChallenge();
        $parts = explode('|', $challenge);

        self::assertCount(2, $parts);
        // Nonce is hex (64 chars for 32 bytes)
        self::assertSame(64, strlen($parts[0]));
        // Timestamp is numeric
        self::assertMatchesRegularExpression('/^\d+$/', $parts[1]);
    }

    // ── Successful verification ────────────────────────────────────────

    #[Test]
    public function verifySucceedsWithValidProof(): void
    {
        $publicKey = 'test-public-key';
        $deviceId = 'device-123';

        $challenge = $this->verifier->generateChallenge();
        $proof = $this->verifier->sign($challenge, $publicKey);
        self::assertNotNull($proof);

        $result = $this->verifier->verify($challenge, $proof, $publicKey, $deviceId);

        self::assertTrue($result->verified);
        self::assertSame(1.0, $result->confidence);
        self::assertSame($deviceId, $result->deviceId);
        self::assertSame('', $result->reason);
    }

    #[Test]
    public function verifySucceedsWithDifferentPublicKeys(): void
    {
        $deviceId = 'device-1';
        $challenge = $this->verifier->generateChallenge();
        $publicKey = 'unique-pk-' . random_bytes(16);

        $proof = $this->verifier->sign($challenge, $publicKey);
        self::assertNotNull($proof);

        $result = $this->verifier->verify($challenge, $proof, $publicKey, $deviceId);

        self::assertTrue($result->verified);
    }

    #[Test]
    public function verifyWithEmptyPublicKeyStillWorks(): void
    {
        $challenge = $this->verifier->generateChallenge();
        $proof = $this->verifier->sign($challenge, '');
        self::assertNotNull($proof);

        $result = $this->verifier->verify($challenge, $proof, '', 'device-1');

        self::assertTrue($result->verified);
    }

    // ── Failed verification: malformed challenge ───────────────────────

    #[Test]
    public function verifyFailsWithMalformedChallenge(): void
    {
        $result = $this->verifier->verify('no-pipe-separator', 'proof', 'pk', 'device-1');

        self::assertFalse($result->verified);
        self::assertSame('Malformed challenge', $result->reason);
        self::assertSame(0.0, $result->confidence);
    }

    #[Test]
    public function verifyFailsWithEmptyChallenge(): void
    {
        $result = $this->verifier->verify('', 'proof', 'pk', 'device-1');

        self::assertFalse($result->verified);
        self::assertSame('Malformed challenge', $result->reason);
    }

    #[Test]
    public function verifyFailsWithTooManyPipeSeparators(): void
    {
        $result = $this->verifier->verify('a|b|c', 'proof', 'pk', 'device-1');

        self::assertFalse($result->verified);
        self::assertSame('Malformed challenge', $result->reason);
    }

    // ── Failed verification: expired challenge ─────────────────────────

    #[Test]
    public function verifyFailsWithExpiredChallenge(): void
    {
        $key = random_bytes(32);
        $keyRing = new EnvKeyRing(['device-proof' => $key]);

        // Create verifier with 0-second max age
        $verifier = new DeviceProofVerifier($keyRing, challengeMaxAge: 0);

        // Forge a challenge with old timestamp
        $challenge = 'nonce123|' . ((string) (time() - 100));
        $proof = $verifier->sign($challenge, 'pk');
        self::assertNotNull($proof);

        $result = $verifier->verify($challenge, $proof, 'pk', 'device-1');

        self::assertFalse($result->verified);
        self::assertSame('Challenge expired', $result->reason);
    }

    #[Test]
    public function verifyFailsWithFutureTimestamp(): void
    {
        // Challenge with timestamp far in the future produces negative age
        $challenge = 'nonce|' . ((string) (time() + 9999));
        $proof = $this->verifier->sign($challenge, 'pk');
        self::assertNotNull($proof);

        $result = $this->verifier->verify($challenge, $proof, 'pk', 'device-1');

        self::assertFalse($result->verified);
        self::assertSame('Challenge expired', $result->reason);
    }

    #[Test]
    public function verifySucceedsWithChallengeJustBeforeExpiry(): void
    {
        $key = random_bytes(32);
        $keyRing = new EnvKeyRing(['device-proof' => $key]);

        // 10 second max age
        $verifier = new DeviceProofVerifier($keyRing, challengeMaxAge: 10);

        // Challenge 5 seconds old (within the 10 second window)
        $challenge = 'validnonce|' . ((string) (time() - 5));
        $proof = $verifier->sign($challenge, 'pk');
        self::assertNotNull($proof);

        $result = $verifier->verify($challenge, $proof, 'pk', 'device-1');

        self::assertTrue($result->verified);
    }

    // ── Failed verification: replay attack ─────────────────────────────

    #[Test]
    public function verifyPreventsReplayAttack(): void
    {
        $publicKey = 'test-pk';
        $deviceId = 'device-1';

        $challenge = $this->verifier->generateChallenge();
        $proof = $this->verifier->sign($challenge, $publicKey);
        self::assertNotNull($proof);

        // First verification succeeds
        $result1 = $this->verifier->verify($challenge, $proof, $publicKey, $deviceId);
        self::assertTrue($result1->verified);

        // Replay attempt fails
        $result2 = $this->verifier->verify($challenge, $proof, $publicKey, $deviceId);
        self::assertFalse($result2->verified);
        self::assertSame('Challenge already used (replay detected)', $result2->reason);
    }

    #[Test]
    public function multipleReplayAttemptsContinueToFail(): void
    {
        $challenge = $this->verifier->generateChallenge();
        $proof = $this->verifier->sign($challenge, 'pk');
        self::assertNotNull($proof);

        // First succeeds
        $this->verifier->verify($challenge, $proof, 'pk', 'dev-1');

        // Multiple replays all fail
        for ($i = 0; $i < 3; $i++) {
            $result = $this->verifier->verify($challenge, $proof, 'pk', 'dev-1');
            self::assertFalse($result->verified);
            self::assertSame('Challenge already used (replay detected)', $result->reason);
        }
    }

    // ── Failed verification: invalid proof ─────────────────────────────

    #[Test]
    public function verifyFailsWithInvalidProof(): void
    {
        $challenge = $this->verifier->generateChallenge();

        $result = $this->verifier->verify($challenge, 'invalid-proof', 'pk', 'device-1');

        self::assertFalse($result->verified);
        self::assertSame('Proof verification failed', $result->reason);
    }

    #[Test]
    public function verifyFailsWithWrongPublicKey(): void
    {
        $challenge = $this->verifier->generateChallenge();
        $proof = $this->verifier->sign($challenge, 'correct-pk');
        self::assertNotNull($proof);

        // Verify with a different public key
        $result = $this->verifier->verify($challenge, $proof, 'wrong-pk', 'device-1');

        self::assertFalse($result->verified);
        self::assertSame('Proof verification failed', $result->reason);
    }

    #[Test]
    public function verifyFailsWithTruncatedProof(): void
    {
        $challenge = $this->verifier->generateChallenge();
        $proof = $this->verifier->sign($challenge, 'pk');
        self::assertNotNull($proof);

        // Truncate the proof
        $truncated = substr($proof, 0, 10);

        $result = $this->verifier->verify($challenge, $truncated, 'pk', 'device-1');

        self::assertFalse($result->verified);
    }

    #[Test]
    public function verifyFailsWithModifiedProof(): void
    {
        $challenge = $this->verifier->generateChallenge();
        $proof = $this->verifier->sign($challenge, 'pk');
        self::assertNotNull($proof);

        // Flip a character in the proof
        $modified = $proof[0] === 'a' ? 'b' . substr($proof, 1) : 'a' . substr($proof, 1);

        $result = $this->verifier->verify($challenge, $modified, 'pk', 'device-1');

        self::assertFalse($result->verified);
    }

    // ── Key unavailability ─────────────────────────────────────────────

    #[Test]
    public function verifyFailsWhenKeyUnavailable(): void
    {
        $keyRing = new EnvKeyRing([]);
        $verifier = new DeviceProofVerifier($keyRing);

        $challenge = 'nonce|' . ((string) time());

        $result = $verifier->verify($challenge, 'proof', 'pk', 'device-1');

        self::assertFalse($result->verified);
        self::assertSame('Verification key unavailable', $result->reason);
    }

    #[Test]
    public function signReturnsNullWhenKeyUnavailable(): void
    {
        $keyRing = new EnvKeyRing([]);
        $verifier = new DeviceProofVerifier($keyRing);

        self::assertNull($verifier->sign('challenge', 'pk'));
    }

    // ── Custom key ID ──────────────────────────────────────────────────

    #[Test]
    public function usesCustomKeyId(): void
    {
        $key = random_bytes(32);
        $keyRing = new EnvKeyRing(['custom-key' => $key]);
        $verifier = new DeviceProofVerifier($keyRing, keyId: 'custom-key');

        $challenge = $verifier->generateChallenge();
        $proof = $verifier->sign($challenge, 'pk');
        self::assertNotNull($proof);

        $result = $verifier->verify($challenge, $proof, 'pk', 'dev-1');

        self::assertTrue($result->verified);
    }

    #[Test]
    public function failsWithWrongKeyId(): void
    {
        $key = random_bytes(32);
        $keyRing = new EnvKeyRing(['some-key' => $key]);
        $verifier = new DeviceProofVerifier($keyRing, keyId: 'different-key');

        $challenge = 'nonce|' . ((string) time());
        $result = $verifier->verify($challenge, 'proof', 'pk', 'dev-1');

        self::assertFalse($result->verified);
        self::assertSame('Verification key unavailable', $result->reason);
    }

    // ── Edge cases ─────────────────────────────────────────────────────

    #[Test]
    public function proofIsConsistentForSameInputs(): void
    {
        $challenge = $this->verifier->generateChallenge();
        $pk = 'stable-pk';

        $proof1 = $this->verifier->sign($challenge, $pk);
        $proof2 = $this->verifier->sign($challenge, $pk);

        self::assertNotNull($proof1);
        self::assertNotNull($proof2);
        self::assertSame($proof1, $proof2);
    }

    #[Test]
    public function differentChallengesProduceDifferentProofs(): void
    {
        $c1 = $this->verifier->generateChallenge();
        $c2 = $this->verifier->generateChallenge();

        $p1 = $this->verifier->sign($c1, 'pk');
        $p2 = $this->verifier->sign($c2, 'pk');

        self::assertNotNull($p1);
        self::assertNotNull($p2);
        self::assertNotSame($p1, $p2);
    }

    #[Test]
    public function largePublicKeyHandledCorrectly(): void
    {
        $challenge = $this->verifier->generateChallenge();
        $largePk = str_repeat('A', 10000);

        $proof = $this->verifier->sign($challenge, $largePk);
        self::assertNotNull($proof);

        $result = $this->verifier->verify($challenge, $proof, $largePk, 'device-1');

        self::assertTrue($result->verified);
    }

    #[Test]
    public function nonNumericTimestampInChallengeTreatsAsExpired(): void
    {
        // Non-numeric timestamp: (int)"abc" = 0, making age = time() which is huge
        $challenge = 'nonce|abc';

        $result = $this->verifier->verify($challenge, 'proof', 'pk', 'device-1');

        self::assertFalse($result->verified);
        self::assertSame('Challenge expired', $result->reason);
    }
}
