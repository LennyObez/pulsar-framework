<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\TwoFactor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\TwoFactor\InMemoryTotpSecretStore;
use Pulsar\Security\Crypto\Encryptor;
use Pulsar\Security\Crypto\MasterKey;
use ReflectionClass;

use function random_bytes;
use function sodium_bin2hex;

#[CoversClass(InMemoryTotpSecretStore::class)]
final class TotpSecretStoreTest extends TestCase
{
    #[Test]
    public function storeAndRetrieveRoundTripWithoutEncryption(): void
    {
        $store = new InMemoryTotpSecretStore();

        $secret = random_bytes(20);
        $store->store('user-1', $secret);

        self::assertSame($secret, $store->retrieve('user-1'));
    }

    #[Test]
    public function storeAndRetrieveRoundTripWithEncryption(): void
    {
        $masterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));
        $encryptor = Encryptor::fromDerivedKey($masterKey, 4, 'totpscrt');
        $store = new InMemoryTotpSecretStore($encryptor);

        $secret = random_bytes(20);
        $store->store('user-1', $secret);

        self::assertSame($secret, $store->retrieve('user-1'));
    }

    #[Test]
    public function retrieveReturnsNullForUnknownIdentity(): void
    {
        $store = new InMemoryTotpSecretStore();

        self::assertNull($store->retrieve('unknown'));
    }

    #[Test]
    public function deleteRemovesSecret(): void
    {
        $store = new InMemoryTotpSecretStore();

        $secret = random_bytes(20);
        $store->store('user-1', $secret);
        self::assertNotNull($store->retrieve('user-1'));

        $store->delete('user-1');
        self::assertNull($store->retrieve('user-1'));
    }

    #[Test]
    public function deleteOnNonexistentIdentityIsNoOp(): void
    {
        $store = new InMemoryTotpSecretStore();
        $store->delete('nonexistent');

        self::assertNull($store->retrieve('nonexistent'));
    }

    #[Test]
    public function differentIdentitiesAreIsolated(): void
    {
        $store = new InMemoryTotpSecretStore();

        $secret1 = random_bytes(20);
        $secret2 = random_bytes(20);

        $store->store('user-1', $secret1);
        $store->store('user-2', $secret2);

        self::assertSame($secret1, $store->retrieve('user-1'));
        self::assertSame($secret2, $store->retrieve('user-2'));
        self::assertNull($store->retrieve('user-3'));
    }

    #[Test]
    public function storeOverwritesPreviousSecret(): void
    {
        $masterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));
        $encryptor = Encryptor::fromDerivedKey($masterKey, 4, 'totpscrt');
        $store = new InMemoryTotpSecretStore($encryptor);

        $secret1 = random_bytes(20);
        $secret2 = random_bytes(20);

        $store->store('user-1', $secret1);
        self::assertSame($secret1, $store->retrieve('user-1'));

        $store->store('user-1', $secret2);
        self::assertSame($secret2, $store->retrieve('user-1'));
    }

    #[Test]
    public function encryptedStorageDoesNotLeakPlaintext(): void
    {
        $masterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));
        $encryptor = Encryptor::fromDerivedKey($masterKey, 4, 'totpscrt');
        $store = new InMemoryTotpSecretStore($encryptor);

        $secret = 'this_is_a_known_secret_string';
        $store->store('user-1', $secret);

        // Retrieve works (decrypted)
        self::assertSame($secret, $store->retrieve('user-1'));

        // Verify internal state is encrypted (not plaintext)
        $reflection = new ReflectionClass($store);
        $property = $reflection->getProperty('secrets');
        /** @var array<string, string> $secrets */
        $secrets = $property->getValue($store);

        self::assertArrayHasKey('user-1', $secrets);
        self::assertNotSame($secret, $secrets['user-1']);
    }
}
