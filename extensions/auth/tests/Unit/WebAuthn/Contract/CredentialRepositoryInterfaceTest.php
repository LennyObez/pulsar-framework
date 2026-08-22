<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit\WebAuthn\Contract;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\WebAuthn\Contract\CredentialRepositoryInterface;
use Pulsar\Extension\Auth\WebAuthn\PublicKey\CredentialSource;

#[CoversNothing]
final class CredentialRepositoryInterfaceTest extends TestCase
{
    #[Test]
    public function stubCanFindById(): void
    {
        $credential = $this->createCredential();

        $stub = $this->createStub(CredentialRepositoryInterface::class);
        $stub->method('findById')->willReturn($credential);

        $result = $stub->findById('cred-1');

        self::assertSame('cred-1', $result->credentialId);
        self::assertSame('user-1', $result->userId);
        self::assertSame(-7, $result->algorithmId);
    }

    #[Test]
    public function stubCanReturnNullForMissingCredential(): void
    {
        $stub = $this->createStub(CredentialRepositoryInterface::class);
        $stub->method('findById')->willReturn(null);

        self::assertNull($stub->findById('nonexistent'));
    }

    #[Test]
    public function stubCanFindByUserId(): void
    {
        $credential = $this->createCredential();

        $stub = $this->createStub(CredentialRepositoryInterface::class);
        $stub->method('findByUserId')->willReturn([$credential]);

        $list = $stub->findByUserId('user-1');

        self::assertCount(1, $list);
        self::assertTrue($list[0]->discoverable);
    }

    #[Test]
    public function stubCanReturnEmptyListForUserWithNoCredentials(): void
    {
        $stub = $this->createStub(CredentialRepositoryInterface::class);
        $stub->method('findByUserId')->willReturn([]);

        self::assertCount(0, $stub->findByUserId('user-no-creds'));
    }

    #[Test]
    public function stubCanPersist(): void
    {
        $credential = $this->createCredential();

        $stub = $this->createStub(CredentialRepositoryInterface::class);

        // persist() returns void
        $stub->persist($credential);
        self::assertSame('cred-1', $credential->credentialId);
    }

    #[Test]
    public function stubCanUpdateCounter(): void
    {
        $stub = $this->createStub(CredentialRepositoryInterface::class);

        // updateCounter() returns void
        $stub->updateCounter('cred-1', 42);
        self::assertSame(42, 42);
    }

    #[Test]
    public function stubCanRemove(): void
    {
        $stub = $this->createStub(CredentialRepositoryInterface::class);

        // remove() returns void
        $stub->remove('cred-1');
        self::assertSame('cred-1', 'cred-1');
    }

    #[Test]
    public function stubCanRemoveByUserId(): void
    {
        $stub = $this->createStub(CredentialRepositoryInterface::class);

        // removeByUserId() returns void
        $stub->removeByUserId('user-1');
        self::assertSame('user-1', 'user-1');
    }

    #[Test]
    public function stubCanCheckExistence(): void
    {
        $stub = $this->createStub(CredentialRepositoryInterface::class);
        $stub->method('exists')->willReturnMap([
            ['cred-1', true],
            ['nonexistent', false],
        ]);

        self::assertTrue($stub->exists('cred-1'));
        self::assertFalse($stub->exists('nonexistent'));
    }

    private function createCredential(): CredentialSource
    {
        return new CredentialSource(
            credentialId: 'cred-1',
            userId: 'user-1',
            publicKeyPem: '-----BEGIN PUBLIC KEY-----\nMFk...\n-----END PUBLIC KEY-----',
            signatureCounter: 5,
            attestationFormat: 'packed',
            transports: ['usb', 'nfc'],
            discoverable: true,
            aaguid: '00000000-0000-0000-0000-000000000001',
            createdAt: new DateTimeImmutable('2026-01-15T10:00:00+00:00'),
            algorithmId: -7,
        );
    }
}
