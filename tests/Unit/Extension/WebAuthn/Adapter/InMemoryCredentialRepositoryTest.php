<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\WebAuthn\Adapter;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\WebAuthn\Adapter\InMemoryCredentialRepository;
use Pulsar\Extension\WebAuthn\PublicKey\CredentialSource;

#[CoversClass(InMemoryCredentialRepository::class)]
final class InMemoryCredentialRepositoryTest extends TestCase
{
    private InMemoryCredentialRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new InMemoryCredentialRepository();
    }

    private function createCredential(string $credentialId, string $userId, int $counter = 0): CredentialSource
    {
        return new CredentialSource(
            credentialId: $credentialId,
            userId: $userId,
            publicKeyPem: '-----BEGIN PUBLIC KEY-----\nMFkw...\n-----END PUBLIC KEY-----',
            signatureCounter: $counter,
            attestationFormat: 'none',
            transports: ['internal'],
            discoverable: true,
            aaguid: '00000000-0000-0000-0000-000000000000',
            createdAt: new DateTimeImmutable(),
        );
    }

    #[Test]
    public function persistAndFindById(): void
    {
        $credential = $this->createCredential('cred-001', 'user-42');

        $this->repo->persist($credential);

        $found = $this->repo->findById('cred-001');

        self::assertNotNull($found);
        self::assertSame('cred-001', $found->credentialId);
        self::assertSame('user-42', $found->userId);
    }

    #[Test]
    public function findByIdReturnsNullForUnknown(): void
    {
        self::assertNull($this->repo->findById('nonexistent'));
    }

    #[Test]
    public function findByUserId(): void
    {
        $this->repo->persist($this->createCredential('cred-a', 'user-42'));
        $this->repo->persist($this->createCredential('cred-b', 'user-42'));
        $this->repo->persist($this->createCredential('cred-c', 'user-99'));

        $credentials = $this->repo->findByUserId('user-42');

        self::assertCount(2, $credentials);
    }

    #[Test]
    public function findByUserIdReturnsEmptyForUnknown(): void
    {
        $credentials = $this->repo->findByUserId('nonexistent');

        self::assertSame([], $credentials);
    }

    #[Test]
    public function updateCounter(): void
    {
        $this->repo->persist($this->createCredential('cred-counter', 'user-42', 5));

        $this->repo->updateCounter('cred-counter', 10);

        $updated = $this->repo->findById('cred-counter');
        self::assertNotNull($updated);
        self::assertSame(10, $updated->signatureCounter);
    }

    #[Test]
    public function updateCounterIgnoresUnknownCredential(): void
    {
        // Should not throw
        $this->repo->updateCounter('nonexistent', 5);

        self::assertNull($this->repo->findById('nonexistent'));
    }

    #[Test]
    public function remove(): void
    {
        $this->repo->persist($this->createCredential('cred-remove', 'user-42'));

        self::assertTrue($this->repo->exists('cred-remove'));

        $this->repo->remove('cred-remove');

        self::assertFalse($this->repo->exists('cred-remove'));
        self::assertNull($this->repo->findById('cred-remove'));
    }

    #[Test]
    public function removeByUserId(): void
    {
        $this->repo->persist($this->createCredential('cred-x', 'user-42'));
        $this->repo->persist($this->createCredential('cred-y', 'user-42'));
        $this->repo->persist($this->createCredential('cred-z', 'user-99'));

        $this->repo->removeByUserId('user-42');

        self::assertFalse($this->repo->exists('cred-x'));
        self::assertFalse($this->repo->exists('cred-y'));
        self::assertTrue($this->repo->exists('cred-z'));
    }

    #[Test]
    public function exists(): void
    {
        self::assertFalse($this->repo->exists('cred-exists'));

        $this->repo->persist($this->createCredential('cred-exists', 'user-42'));

        self::assertTrue($this->repo->exists('cred-exists'));
    }

    #[Test]
    public function cloneDetectionViaCounterValidation(): void
    {
        $this->repo->persist($this->createCredential('cred-clone', 'user-42', 5));

        // Counter increased — normal
        $this->repo->updateCounter('cred-clone', 10);
        $credential = $this->repo->findById('cred-clone');
        self::assertNotNull($credential);
        self::assertSame(10, $credential->signatureCounter);

        // A cloned authenticator might present a lower counter
        // The repository stores whatever counter is set — clone detection
        // is done at the ceremony level, not the repository level
        $this->repo->updateCounter('cred-clone', 3);
        $credential = $this->repo->findById('cred-clone');
        self::assertNotNull($credential);
        self::assertSame(3, $credential->signatureCounter);
    }
}
