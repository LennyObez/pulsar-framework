<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\WebAuthn\Adapter;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\WebAuthn\Adapter\InMemoryAuthenticatorRepository;
use Pulsar\Extension\WebAuthn\Authenticator\AuthenticatorRecord;
use Pulsar\Extension\WebAuthn\Authenticator\AuthenticatorType;

#[CoversClass(InMemoryAuthenticatorRepository::class)]
final class InMemoryAuthenticatorRepositoryTest extends TestCase
{
    private InMemoryAuthenticatorRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new InMemoryAuthenticatorRepository();
    }

    private function makeRecord(
        string $credentialId = 'cred-abc123',
        string $userId = 'user-550e8400-e29b-41d4-a716-446655440000',
        string $displayName = 'YubiKey 5 NFC',
        AuthenticatorType $type = AuthenticatorType::CrossPlatform,
        bool $active = true,
    ): AuthenticatorRecord {
        return new AuthenticatorRecord(
            credentialId: $credentialId,
            userId: $userId,
            displayName: $displayName,
            type: $type,
            aaguid: 'fbfc3007-154e-4ecc-8c0b-6e020557d7bd',
            active: $active,
            registeredAt: new DateTimeImmutable('2025-11-15T09:30:00+00:00'),
            lastUsedAt: new DateTimeImmutable('2025-12-01T14:22:00+00:00'),
        );
    }

    #[Test]
    public function findByCredentialIdReturnsNullWhenNotRegistered(): void
    {
        self::assertNull($this->repo->findByCredentialId('nonexistent-cred'));
    }

    #[Test]
    public function registerAndFindByCredentialId(): void
    {
        $record = $this->makeRecord();
        $this->repo->register($record);

        $found = $this->repo->findByCredentialId('cred-abc123');
        self::assertNotNull($found);
        self::assertSame('cred-abc123', $found->credentialId);
        self::assertSame('YubiKey 5 NFC', $found->displayName);
    }

    #[Test]
    public function listByUserIdReturnsEmptyArrayWhenNoRecords(): void
    {
        self::assertSame([], $this->repo->listByUserId('user-nonexistent'));
    }

    #[Test]
    public function listByUserIdReturnsOnlyMatchingUser(): void
    {
        $this->repo->register($this->makeRecord('cred-1', 'user-a'));
        $this->repo->register($this->makeRecord('cred-2', 'user-a'));
        $this->repo->register($this->makeRecord('cred-3', 'user-b'));

        $results = $this->repo->listByUserId('user-a');
        self::assertCount(2, $results);

        $ids = array_map(static fn(AuthenticatorRecord $r): string => $r->credentialId, $results);
        self::assertContains('cred-1', $ids);
        self::assertContains('cred-2', $ids);
    }

    #[Test]
    public function renameUpdatesDisplayName(): void
    {
        $this->repo->register($this->makeRecord('cred-1', 'user-a', 'Old Name'));
        $this->repo->rename('cred-1', 'New YubiKey Name');

        $found = $this->repo->findByCredentialId('cred-1');
        self::assertNotNull($found);
        self::assertSame('New YubiKey Name', $found->displayName);
        self::assertSame('user-a', $found->userId);
    }

    #[Test]
    public function renameDoesNothingForNonexistentCredential(): void
    {
        $this->repo->rename('nonexistent-cred', 'New Name');

        self::assertNull($this->repo->findByCredentialId('nonexistent-cred'));
    }

    #[Test]
    public function revokeDeactivatesCredential(): void
    {
        $this->repo->register($this->makeRecord('cred-1', 'user-a'));
        $record = $this->repo->findByCredentialId('cred-1');
        self::assertNotNull($record);
        self::assertTrue($record->active);

        $this->repo->revoke('cred-1');

        $found = $this->repo->findByCredentialId('cred-1');
        self::assertNotNull($found);
        self::assertFalse($found->active);
        self::assertSame('cred-1', $found->credentialId);
    }

    #[Test]
    public function revokeDoesNothingForNonexistentCredential(): void
    {
        $this->repo->revoke('nonexistent-cred');

        self::assertNull($this->repo->findByCredentialId('nonexistent-cred'));
    }

    #[Test]
    public function countActiveReturnsZeroWhenNoRecords(): void
    {
        self::assertSame(0, $this->repo->countActive('user-nonexistent'));
    }

    #[Test]
    public function countActiveCountsOnlyActiveRecordsForUser(): void
    {
        $this->repo->register($this->makeRecord('cred-1', 'user-a', active: true));
        $this->repo->register($this->makeRecord('cred-2', 'user-a', active: true));
        $this->repo->register($this->makeRecord('cred-3', 'user-a', active: false));
        $this->repo->register($this->makeRecord('cred-4', 'user-b', active: true));

        self::assertSame(2, $this->repo->countActive('user-a'));
        self::assertSame(1, $this->repo->countActive('user-b'));
    }

    #[Test]
    public function countActiveReflectsRevocation(): void
    {
        $this->repo->register($this->makeRecord('cred-1', 'user-a'));
        $this->repo->register($this->makeRecord('cred-2', 'user-a'));
        self::assertSame(2, $this->repo->countActive('user-a'));

        $this->repo->revoke('cred-1');
        self::assertSame(1, $this->repo->countActive('user-a'));
    }

    #[Test]
    public function renamePreservesAllOtherFields(): void
    {
        $record = $this->makeRecord('cred-1', 'user-a', 'Original');
        $this->repo->register($record);

        $this->repo->rename('cred-1', 'Renamed');
        $found = $this->repo->findByCredentialId('cred-1');
        self::assertNotNull($found);

        self::assertSame($record->userId, $found->userId);
        self::assertSame($record->type, $found->type);
        self::assertSame($record->aaguid, $found->aaguid);
        self::assertSame($record->active, $found->active);
        self::assertSame($record->registeredAt, $found->registeredAt);
        self::assertSame($record->lastUsedAt, $found->lastUsedAt);
    }

    #[Test]
    public function revokePreservesAllOtherFields(): void
    {
        $record = $this->makeRecord('cred-1', 'user-a');
        $this->repo->register($record);

        $this->repo->revoke('cred-1');
        $found = $this->repo->findByCredentialId('cred-1');
        self::assertNotNull($found);

        self::assertSame($record->credentialId, $found->credentialId);
        self::assertSame($record->userId, $found->userId);
        self::assertSame($record->displayName, $found->displayName);
        self::assertSame($record->type, $found->type);
        self::assertSame($record->aaguid, $found->aaguid);
        self::assertFalse($found->active);
    }
}
