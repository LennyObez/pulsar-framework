<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ZeroTrust\DeviceIdentity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Crypto\EnvKeyRing;
use Pulsar\Security\ZeroTrust\DeviceIdentity\Internal\DeviceProofVerifier;

use function random_bytes;

#[CoversClass(DeviceProofVerifier::class)]
final class DeviceProofVerifierTest extends TestCase
{
    private DeviceProofVerifier $verifier;

    protected function setUp(): void
    {
        $key = random_bytes(32);
        $keyRing = new EnvKeyRing(['device-proof' => $key]);
        $this->verifier = new DeviceProofVerifier($keyRing);
    }

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
        $c1 = $this->verifier->generateChallenge();
        $c2 = $this->verifier->generateChallenge();

        self::assertNotSame($c1, $c2);
    }

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
    }

    #[Test]
    public function verifyFailsWithInvalidProof(): void
    {
        $challenge = $this->verifier->generateChallenge();

        $result = $this->verifier->verify($challenge, 'invalid-proof', 'pk', 'device-1');

        self::assertFalse($result->verified);
        self::assertSame('Proof verification failed', $result->reason);
    }

    #[Test]
    public function verifyFailsWithMalformedChallenge(): void
    {
        $result = $this->verifier->verify('no-pipe-separator', 'proof', 'pk', 'device-1');

        self::assertFalse($result->verified);
        self::assertSame('Malformed challenge', $result->reason);
    }

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
}
