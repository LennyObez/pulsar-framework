<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ZeroTrust\DeviceIdentity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Crypto\EnvKeyRing;
use Pulsar\Security\ZeroTrust\DeviceIdentity\DeviceIdentity;
use Pulsar\Security\ZeroTrust\DeviceIdentity\Internal\InMemoryDeviceRegistry;

use function random_bytes;

#[CoversClass(InMemoryDeviceRegistry::class)]
#[CoversClass(DeviceIdentity::class)]
final class InMemoryDeviceRegistryTest extends TestCase
{
    private InMemoryDeviceRegistry $registry;

    protected function setUp(): void
    {
        $key = random_bytes(32);
        $keyRing = new EnvKeyRing(['device-registry' => $key]);
        $this->registry = new InMemoryDeviceRegistry($keyRing);
    }

    #[Test]
    public function registerCreatesDeviceWithCorrectData(): void
    {
        $device = $this->registry->register(
            identityId: 'user-123',
            fingerprint: 'fp-hash-abc',
            attestationType: 'webauthn',
            publicKey: 'pk-base64',
            metadata: ['os' => 'Linux'],
        );

        self::assertNotEmpty($device->deviceId);
        self::assertSame('user-123', $device->identityId);
        self::assertSame('fp-hash-abc', $device->fingerprint);
        self::assertSame('webauthn', $device->attestationType);
        self::assertSame('pk-base64', $device->publicKey);
        self::assertNull($device->lastVerifiedAt);
        self::assertSame(['os' => 'Linux'], $device->metadata);
    }

    #[Test]
    public function findReturnsRegisteredDevice(): void
    {
        $device = $this->registry->register('user-1', 'fp', 'webauthn', 'pk');

        $found = $this->registry->find($device->deviceId);

        self::assertNotNull($found);
        self::assertSame($device->deviceId, $found->deviceId);
    }

    #[Test]
    public function findReturnsNullForUnknownDevice(): void
    {
        self::assertNull($this->registry->find('nonexistent'));
    }

    #[Test]
    public function findByIdentityReturnsAllDevicesForIdentity(): void
    {
        $this->registry->register('user-1', 'fp1', 'webauthn', 'pk1');
        $this->registry->register('user-1', 'fp2', 'webauthn', 'pk2');
        $this->registry->register('user-2', 'fp3', 'webauthn', 'pk3');

        $devices = $this->registry->findByIdentity('user-1');

        self::assertCount(2, $devices);
        self::assertSame('user-1', $devices[0]->identityId);
        self::assertSame('user-1', $devices[1]->identityId);
    }

    #[Test]
    public function findByIdentityReturnsEmptyForUnknownIdentity(): void
    {
        self::assertSame([], $this->registry->findByIdentity('nobody'));
    }

    #[Test]
    public function verifySucceedsWithValidChallengeAndProof(): void
    {
        $device = $this->registry->register('user-1', 'fp', 'webauthn', 'pk');

        $challenge = $this->registry->issueChallenge($device->deviceId);
        self::assertNotNull($challenge);

        $proof = $this->registry->generateProof($device->deviceId, $challenge);
        self::assertNotNull($proof);

        $result = $this->registry->verify($device->deviceId, $challenge, $proof);

        self::assertTrue($result->verified);
        self::assertSame(1.0, $result->confidence);
        self::assertSame($device->deviceId, $result->deviceId);
    }

    #[Test]
    public function verifyFailsForUnknownDevice(): void
    {
        $result = $this->registry->verify('unknown', 'challenge', 'proof');

        self::assertFalse($result->verified);
        self::assertSame('Device not found', $result->reason);
    }

    #[Test]
    public function verifyFailsWithInvalidChallenge(): void
    {
        $device = $this->registry->register('user-1', 'fp', 'webauthn', 'pk');
        $this->registry->issueChallenge($device->deviceId);

        $result = $this->registry->verify($device->deviceId, 'wrong-challenge', 'proof');

        self::assertFalse($result->verified);
        self::assertSame('Invalid or expired challenge', $result->reason);
    }

    #[Test]
    public function verifyFailsWithInvalidProof(): void
    {
        $device = $this->registry->register('user-1', 'fp', 'webauthn', 'pk');
        $challenge = $this->registry->issueChallenge($device->deviceId);
        self::assertNotNull($challenge);

        $result = $this->registry->verify($device->deviceId, $challenge, 'bad-proof');

        self::assertFalse($result->verified);
        self::assertSame('Proof verification failed', $result->reason);
    }

    #[Test]
    public function verifyPreventsReplay(): void
    {
        $device = $this->registry->register('user-1', 'fp', 'webauthn', 'pk');
        $challenge = $this->registry->issueChallenge($device->deviceId);
        self::assertNotNull($challenge);

        $proof = $this->registry->generateProof($device->deviceId, $challenge);
        self::assertNotNull($proof);

        // First verification succeeds
        $result1 = $this->registry->verify($device->deviceId, $challenge, $proof);
        self::assertTrue($result1->verified);

        // Replay attempt fails (challenge consumed)
        $result2 = $this->registry->verify($device->deviceId, $challenge, $proof);
        self::assertFalse($result2->verified);
        self::assertSame('Invalid or expired challenge', $result2->reason);
    }

    #[Test]
    public function verifyUpdatesLastVerifiedAt(): void
    {
        $device = $this->registry->register('user-1', 'fp', 'webauthn', 'pk');
        self::assertNull($device->lastVerifiedAt);

        $challenge = $this->registry->issueChallenge($device->deviceId);
        self::assertNotNull($challenge);

        $proof = $this->registry->generateProof($device->deviceId, $challenge);
        self::assertNotNull($proof);

        $this->registry->verify($device->deviceId, $challenge, $proof);

        $updated = $this->registry->find($device->deviceId);
        self::assertNotNull($updated);
        self::assertNotNull($updated->lastVerifiedAt);
    }

    #[Test]
    public function revokeRemovesDevice(): void
    {
        $device = $this->registry->register('user-1', 'fp', 'webauthn', 'pk');

        $this->registry->revoke($device->deviceId);

        self::assertNull($this->registry->find($device->deviceId));
        self::assertSame([], $this->registry->findByIdentity('user-1'));
    }

    #[Test]
    public function revokeDoesNotThrowForUnknownDevice(): void
    {
        $this->registry->revoke('nonexistent');

        self::assertNull($this->registry->find('nonexistent'));
    }

    #[Test]
    public function issueChallengeReturnsNullForUnknownDevice(): void
    {
        self::assertNull($this->registry->issueChallenge('nonexistent'));
    }

    #[Test]
    public function generateProofReturnsNullForUnknownDevice(): void
    {
        self::assertNull($this->registry->generateProof('nonexistent', 'challenge'));
    }
}
