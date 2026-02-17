<?php

declare(strict_types=1);

namespace Pulsar\Extension\WebAuthn\Tests\Unit\Adapter;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\WebAuthn\Adapter\InMemoryAuthenticatorRepository;
use Pulsar\Extension\WebAuthn\Authenticator\AuthenticatorRecord;
use Pulsar\Extension\WebAuthn\Authenticator\AuthenticatorType;

final class InMemoryAuthenticatorRepositoryTest extends TestCase
{
    private InMemoryAuthenticatorRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new InMemoryAuthenticatorRepository();
    }

    #[Test]
    public function findByCredentialIdReturnsNullWhenEmpty(): void
    {
        self::assertNull($this->repo->findByCredentialId('cred-1'));
    }

    #[Test]
    public function registerAndFind(): void
    {
        $record = new AuthenticatorRecord(
            credentialId: 'cred-1',
            userId: 'user-1',
            displayName: 'My Key',
            type: AuthenticatorType::CrossPlatform,
            aaguid: '00000000-0000-0000-0000-000000000000',
            active: true,
            registeredAt: new DateTimeImmutable('2025-01-01'),
        );

        $this->repo->register($record);

        $found = $this->repo->findByCredentialId('cred-1');
        self::assertNotNull($found);
        self::assertSame('cred-1', $found->credentialId);
        self::assertSame('user-1', $found->userId);
        self::assertSame('My Key', $found->displayName);
        self::assertSame(AuthenticatorType::CrossPlatform, $found->type);
        self::assertTrue($found->active);
    }

    #[Test]
    public function listByUserIdReturnsMatchingRecords(): void
    {
        $this->repo->register(new AuthenticatorRecord(
            credentialId: 'cred-1',
            userId: 'user-1',
            displayName: 'Key A',
            type: AuthenticatorType::Platform,
            aaguid: 'aaguid-1',
            active: true,
            registeredAt: new DateTimeImmutable(),
        ));
        $this->repo->register(new AuthenticatorRecord(
            credentialId: 'cred-2',
            userId: 'user-2',
            displayName: 'Key B',
            type: AuthenticatorType::CrossPlatform,
            aaguid: 'aaguid-2',
            active: true,
            registeredAt: new DateTimeImmutable(),
        ));
        $this->repo->register(new AuthenticatorRecord(
            credentialId: 'cred-3',
            userId: 'user-1',
            displayName: 'Key C',
            type: AuthenticatorType::Platform,
            aaguid: 'aaguid-3',
            active: true,
            registeredAt: new DateTimeImmutable(),
        ));

        $records = $this->repo->listByUserId('user-1');

        self::assertCount(2, $records);
        self::assertSame('cred-1', $records[0]->credentialId);
        self::assertSame('cred-3', $records[1]->credentialId);
    }

    #[Test]
    public function listByUserIdReturnsEmptyForUnknownUser(): void
    {
        self::assertSame([], $this->repo->listByUserId('unknown'));
    }

    #[Test]
    public function renameUpdatesDisplayName(): void
    {
        $this->repo->register(new AuthenticatorRecord(
            credentialId: 'cred-1',
            userId: 'user-1',
            displayName: 'Old Name',
            type: AuthenticatorType::CrossPlatform,
            aaguid: 'aaguid-1',
            active: true,
            registeredAt: new DateTimeImmutable(),
        ));

        $this->repo->rename('cred-1', 'New Name');

        $found = $this->repo->findByCredentialId('cred-1');
        self::assertNotNull($found);
        self::assertSame('New Name', $found->displayName);
        self::assertTrue($found->active);
    }

    #[Test]
    public function renameNonExistentIsNoOp(): void
    {
        $this->repo->rename('does-not-exist', 'Name');

        self::assertNull($this->repo->findByCredentialId('does-not-exist'));
    }

    #[Test]
    public function revokeDeactivatesRecord(): void
    {
        $this->repo->register(new AuthenticatorRecord(
            credentialId: 'cred-1',
            userId: 'user-1',
            displayName: 'Key',
            type: AuthenticatorType::Platform,
            aaguid: 'aaguid-1',
            active: true,
            registeredAt: new DateTimeImmutable(),
        ));

        $this->repo->revoke('cred-1');

        $found = $this->repo->findByCredentialId('cred-1');
        self::assertNotNull($found);
        self::assertFalse($found->active);
    }

    #[Test]
    public function revokeNonExistentIsNoOp(): void
    {
        $this->repo->revoke('does-not-exist');

        self::assertNull($this->repo->findByCredentialId('does-not-exist'));
    }

    #[Test]
    public function countActiveReturnsOnlyActiveRecords(): void
    {
        $this->repo->register(new AuthenticatorRecord(
            credentialId: 'cred-1',
            userId: 'user-1',
            displayName: 'Key A',
            type: AuthenticatorType::Platform,
            aaguid: 'a1',
            active: true,
            registeredAt: new DateTimeImmutable(),
        ));
        $this->repo->register(new AuthenticatorRecord(
            credentialId: 'cred-2',
            userId: 'user-1',
            displayName: 'Key B',
            type: AuthenticatorType::CrossPlatform,
            aaguid: 'a2',
            active: true,
            registeredAt: new DateTimeImmutable(),
        ));
        $this->repo->register(new AuthenticatorRecord(
            credentialId: 'cred-3',
            userId: 'user-1',
            displayName: 'Key C',
            type: AuthenticatorType::Platform,
            aaguid: 'a3',
            active: false,
            registeredAt: new DateTimeImmutable(),
        ));

        self::assertSame(2, $this->repo->countActive('user-1'));
    }

    #[Test]
    public function countActiveReturnsZeroForUnknownUser(): void
    {
        self::assertSame(0, $this->repo->countActive('unknown'));
    }

    #[Test]
    public function countActiveDecrementsAfterRevoke(): void
    {
        $this->repo->register(new AuthenticatorRecord(
            credentialId: 'cred-1',
            userId: 'user-1',
            displayName: 'Key',
            type: AuthenticatorType::Platform,
            aaguid: 'a1',
            active: true,
            registeredAt: new DateTimeImmutable(),
        ));

        self::assertSame(1, $this->repo->countActive('user-1'));

        $this->repo->revoke('cred-1');

        self::assertSame(0, $this->repo->countActive('user-1'));
    }

    #[Test]
    public function registerOverwritesExistingCredential(): void
    {
        $this->repo->register(new AuthenticatorRecord(
            credentialId: 'cred-1',
            userId: 'user-1',
            displayName: 'Original',
            type: AuthenticatorType::Platform,
            aaguid: 'a1',
            active: true,
            registeredAt: new DateTimeImmutable(),
        ));

        $this->repo->register(new AuthenticatorRecord(
            credentialId: 'cred-1',
            userId: 'user-1',
            displayName: 'Replaced',
            type: AuthenticatorType::CrossPlatform,
            aaguid: 'a1',
            active: true,
            registeredAt: new DateTimeImmutable(),
        ));

        $found = $this->repo->findByCredentialId('cred-1');
        self::assertNotNull($found);
        self::assertSame('Replaced', $found->displayName);
    }
}
