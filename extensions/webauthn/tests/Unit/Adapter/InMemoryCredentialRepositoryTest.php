<?php

declare(strict_types=1);

namespace Pulsar\Extension\WebAuthn\Tests\Unit\Adapter;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\WebAuthn\Adapter\InMemoryCredentialRepository;
use Pulsar\Extension\WebAuthn\PublicKey\CredentialSource;

final class InMemoryCredentialRepositoryTest extends TestCase
{
    private InMemoryCredentialRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new InMemoryCredentialRepository();
    }

    private function makeCredential(string $id = 'cred-1', string $userId = 'user-1', int $counter = 0): CredentialSource
    {
        return new CredentialSource(
            credentialId: $id,
            userId: $userId,
            publicKeyPem: '-----BEGIN PUBLIC KEY-----test-----END PUBLIC KEY-----',
            signatureCounter: $counter,
            attestationFormat: 'none',
            transports: ['internal'],
            discoverable: true,
            aaguid: '00000000-0000-0000-0000-000000000000',
            createdAt: new DateTimeImmutable(),
        );
    }

    #[Test]
    public function persist_and_find_by_id(): void
    {
        $credential = $this->makeCredential();
        $this->repo->persist($credential);

        $found = $this->repo->findById('cred-1');

        self::assertNotNull($found);
        self::assertSame('cred-1', $found->credentialId);
        self::assertSame('user-1', $found->userId);
    }

    #[Test]
    public function find_by_id_returns_null_for_unknown(): void
    {
        self::assertNull($this->repo->findById('nonexistent'));
    }

    #[Test]
    public function find_by_user_id_returns_all_user_credentials(): void
    {
        $this->repo->persist($this->makeCredential('cred-1', 'user-1'));
        $this->repo->persist($this->makeCredential('cred-2', 'user-1'));
        $this->repo->persist($this->makeCredential('cred-3', 'user-2'));

        $credentials = $this->repo->findByUserId('user-1');

        self::assertCount(2, $credentials);
    }

    #[Test]
    public function find_by_user_id_returns_empty_for_unknown_user(): void
    {
        self::assertSame([], $this->repo->findByUserId('nonexistent'));
    }

    #[Test]
    public function update_counter(): void
    {
        $this->repo->persist($this->makeCredential('cred-1', 'user-1', 5));

        $this->repo->updateCounter('cred-1', 10);

        $found = $this->repo->findById('cred-1');
        self::assertNotNull($found);
        self::assertSame(10, $found->signatureCounter);
    }

    #[Test]
    public function update_counter_noop_for_unknown_credential(): void
    {
        $this->repo->updateCounter('nonexistent', 99);

        self::assertNull($this->repo->findById('nonexistent'));
    }

    #[Test]
    public function remove_credential(): void
    {
        $this->repo->persist($this->makeCredential());
        self::assertTrue($this->repo->exists('cred-1'));

        $this->repo->remove('cred-1');
        self::assertFalse($this->repo->exists('cred-1'));
    }

    #[Test]
    public function remove_by_user_id(): void
    {
        $this->repo->persist($this->makeCredential('cred-1', 'user-1'));
        $this->repo->persist($this->makeCredential('cred-2', 'user-1'));
        $this->repo->persist($this->makeCredential('cred-3', 'user-2'));

        $this->repo->removeByUserId('user-1');

        self::assertFalse($this->repo->exists('cred-1'));
        self::assertFalse($this->repo->exists('cred-2'));
        self::assertTrue($this->repo->exists('cred-3'));
    }

    #[Test]
    public function exists_returns_correct_values(): void
    {
        self::assertFalse($this->repo->exists('cred-1'));

        $this->repo->persist($this->makeCredential());

        self::assertTrue($this->repo->exists('cred-1'));
    }
}
