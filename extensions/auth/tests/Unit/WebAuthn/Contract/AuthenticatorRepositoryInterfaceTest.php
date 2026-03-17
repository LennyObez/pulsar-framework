<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit\WebAuthn\Contract;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\WebAuthn\Authenticator\AuthenticatorRecord;
use Pulsar\Extension\Auth\WebAuthn\Authenticator\AuthenticatorType;
use Pulsar\Extension\Auth\WebAuthn\Contract\AuthenticatorRepositoryInterface;

#[CoversClass(AuthenticatorRepositoryInterface::class)]
final class AuthenticatorRepositoryInterfaceTest extends TestCase
{
    #[Test]
    public function stubCanFindByCredentialId(): void
    {
        $record = $this->createRecord();

        $stub = $this->createStub(AuthenticatorRepositoryInterface::class);
        $stub->method('findByCredentialId')->willReturn($record);

        $result = $stub->findByCredentialId('cred-1');

        self::assertSame('cred-1', $result->credentialId);
        self::assertSame('user-1', $result->userId);
        self::assertSame('My YubiKey', $result->displayName);
    }

    #[Test]
    public function stubCanReturnNullForMissingCredential(): void
    {
        $stub = $this->createStub(AuthenticatorRepositoryInterface::class);
        $stub->method('findByCredentialId')->willReturn(null);

        self::assertNull($stub->findByCredentialId('nonexistent'));
    }

    #[Test]
    public function stubCanListByUserId(): void
    {
        $record = $this->createRecord();

        $stub = $this->createStub(AuthenticatorRepositoryInterface::class);
        $stub->method('listByUserId')->willReturn([$record]);

        $list = $stub->listByUserId('user-1');

        self::assertCount(1, $list);
        self::assertSame(AuthenticatorType::CrossPlatform, $list[0]->type);
    }

    #[Test]
    public function stubCanReturnEmptyListForUser(): void
    {
        $stub = $this->createStub(AuthenticatorRepositoryInterface::class);
        $stub->method('listByUserId')->willReturn([]);

        self::assertCount(0, $stub->listByUserId('user-no-keys'));
    }

    #[Test]
    public function stubCanRegister(): void
    {
        $record = $this->createRecord();

        $stub = $this->createStub(AuthenticatorRepositoryInterface::class);

        // register() returns void -- no exception means success
        $stub->register($record);
        self::assertSame('cred-1', $record->credentialId);
    }

    #[Test]
    public function stubCanRename(): void
    {
        $stub = $this->createStub(AuthenticatorRepositoryInterface::class);

        // rename() returns void -- no exception means success
        $stub->rename('cred-1', 'Updated Key Name');
        self::assertSame('cred-1', 'cred-1');
    }

    #[Test]
    public function stubCanRevoke(): void
    {
        $stub = $this->createStub(AuthenticatorRepositoryInterface::class);

        // revoke() returns void -- no exception means success
        $stub->revoke('cred-1');
        self::assertSame('cred-1', 'cred-1');
    }

    #[Test]
    public function stubCanCountActive(): void
    {
        $stub = $this->createStub(AuthenticatorRepositoryInterface::class);
        $stub->method('countActive')->willReturn(2);

        self::assertSame(2, $stub->countActive('user-1'));
    }

    #[Test]
    public function stubCanReturnZeroActiveCount(): void
    {
        $stub = $this->createStub(AuthenticatorRepositoryInterface::class);
        $stub->method('countActive')->willReturn(0);

        self::assertSame(0, $stub->countActive('user-no-keys'));
    }

    private function createRecord(): AuthenticatorRecord
    {
        return new AuthenticatorRecord(
            credentialId: 'cred-1',
            userId: 'user-1',
            displayName: 'My YubiKey',
            type: AuthenticatorType::CrossPlatform,
            aaguid: '00000000-0000-0000-0000-000000000001',
            active: true,
            registeredAt: new DateTimeImmutable('2026-01-15T10:00:00+00:00'),
            lastUsedAt: new DateTimeImmutable('2026-03-15T08:30:00+00:00'),
        );
    }
}
