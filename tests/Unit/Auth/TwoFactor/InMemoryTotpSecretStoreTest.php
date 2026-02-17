<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\TwoFactor;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\TwoFactor\InMemoryTotpSecretStore;
use Pulsar\Security\Crypto\EncryptorInterface;

final class InMemoryTotpSecretStoreTest extends TestCase
{
    #[Test]
    public function retrieve_returns_null_for_unknown_identity(): void
    {
        $store = new InMemoryTotpSecretStore();

        self::assertNull($store->retrieve('unknown'));
    }

    #[Test]
    public function store_and_retrieve_without_encryption(): void
    {
        $store = new InMemoryTotpSecretStore();

        $store->store('user-1', 'JBSWY3DPEHPK3PXP');

        self::assertSame('JBSWY3DPEHPK3PXP', $store->retrieve('user-1'));
    }

    #[Test]
    public function store_and_retrieve_with_encryption(): void
    {
        $encryptor = $this->createStub(EncryptorInterface::class);
        $encryptor->method('encrypt')->willReturn('encrypted-secret');
        $encryptor->method('decrypt')->willReturn('JBSWY3DPEHPK3PXP');

        $store = new InMemoryTotpSecretStore($encryptor);

        $store->store('user-1', 'JBSWY3DPEHPK3PXP');

        self::assertSame('JBSWY3DPEHPK3PXP', $store->retrieve('user-1'));
    }

    #[Test]
    public function delete_removes_stored_secret(): void
    {
        $store = new InMemoryTotpSecretStore();

        $store->store('user-1', 'secret');
        $store->delete('user-1');

        self::assertNull($store->retrieve('user-1'));
    }

    #[Test]
    public function delete_is_safe_for_unknown_identity(): void
    {
        $store = new InMemoryTotpSecretStore();

        $store->delete('unknown');

        self::assertNull($store->retrieve('unknown'));
    }

    #[Test]
    public function store_overwrites_previous_secret(): void
    {
        $store = new InMemoryTotpSecretStore();

        $store->store('user-1', 'secret-1');
        $store->store('user-1', 'secret-2');

        self::assertSame('secret-2', $store->retrieve('user-1'));
    }

    #[Test]
    public function different_identities_are_isolated(): void
    {
        $store = new InMemoryTotpSecretStore();

        $store->store('user-1', 'secret-1');
        $store->store('user-2', 'secret-2');

        self::assertSame('secret-1', $store->retrieve('user-1'));
        self::assertSame('secret-2', $store->retrieve('user-2'));
    }
}
