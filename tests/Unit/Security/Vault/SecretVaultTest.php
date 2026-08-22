<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Vault;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Security\Exception\SecurityException;
use Pulsar\Security\Vault\SecretVault;

use function bin2hex;
use function random_bytes;
use function sys_get_temp_dir;

final class SecretVaultTest extends TestCase
{
    private string $vaultPath;
    private MasterKey $masterKey;

    protected function setUp(): void
    {
        $this->vaultPath = sys_get_temp_dir() . '/pulsar_vault_test_' . bin2hex(random_bytes(4)) . '.php';
        $this->masterKey = MasterKey::fromHex(bin2hex(random_bytes(32)));
    }

    protected function tearDown(): void
    {
        if (file_exists($this->vaultPath)) {
            $real = realpath($this->vaultPath);
            $realTmp = realpath(sys_get_temp_dir());
            if ($real !== false && $realTmp !== false && str_starts_with($real, $realTmp)) {
                unlink($real);
            }
        }
    }

    #[Test]
    public function setAndGetRoundTrip(): void
    {
        $vault = SecretVault::create($this->masterKey, $this->vaultPath);

        $vault->set('DB_PASSWORD', 's3cret!');
        $retrieved = $vault->get('DB_PASSWORD');

        self::assertSame('s3cret!', $retrieved);
    }

    #[Test]
    public function hasReturnsTrueForExistingKey(): void
    {
        $vault = SecretVault::create($this->masterKey, $this->vaultPath);
        $vault->set('API_KEY', 'abc123');

        self::assertTrue($vault->has('API_KEY'));
    }

    #[Test]
    public function hasReturnsFalseForMissingKey(): void
    {
        $vault = SecretVault::create($this->masterKey, $this->vaultPath);

        self::assertFalse($vault->has('NONEXISTENT'));
    }

    #[Test]
    public function getThrowsForMissingKey(): void
    {
        $vault = SecretVault::create($this->masterKey, $this->vaultPath);

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessageIsOrContains('not found');

        $vault->get('NONEXISTENT');
    }

    #[Test]
    public function removeDeletesKey(): void
    {
        $vault = SecretVault::create($this->masterKey, $this->vaultPath);
        $vault->set('TEMP_KEY', 'value');
        $vault->remove('TEMP_KEY');

        self::assertFalse($vault->has('TEMP_KEY'));
    }

    #[Test]
    public function keysReturnsAllStoredKeys(): void
    {
        $vault = SecretVault::create($this->masterKey, $this->vaultPath);
        $vault->set('KEY_A', 'a');
        $vault->set('KEY_B', 'b');

        $keys = $vault->keys();

        self::assertContains('KEY_A', $keys);
        self::assertContains('KEY_B', $keys);
    }

    #[Test]
    public function countReturnsNumberOfSecrets(): void
    {
        $vault = SecretVault::create($this->masterKey, $this->vaultPath);

        self::assertSame(0, $vault->count());

        $vault->set('ONE', '1');
        $vault->set('TWO', '2');

        self::assertSame(2, $vault->count());
    }

    #[Test]
    public function secretsPersistAcrossInstances(): void
    {
        $vault1 = SecretVault::create($this->masterKey, $this->vaultPath);
        $vault1->set('PERSIST_KEY', 'persistent_value');

        // Create a new instance pointing to the same file
        $vault2 = SecretVault::create($this->masterKey, $this->vaultPath);
        $value = $vault2->get('PERSIST_KEY');

        self::assertSame('persistent_value', $value);
    }

    #[Test]
    public function emptyVaultFileReturnsNoKeys(): void
    {
        $vault = SecretVault::create($this->masterKey, $this->vaultPath);

        self::assertSame([], $vault->keys());
        self::assertSame(0, $vault->count());
    }

    #[Test]
    public function overwriteExistingKey(): void
    {
        $vault = SecretVault::create($this->masterKey, $this->vaultPath);
        $vault->set('KEY', 'original');
        $vault->set('KEY', 'updated');

        self::assertSame('updated', $vault->get('KEY'));
        self::assertSame(1, $vault->count());
    }

    #[Test]
    public function specialCharactersInSecretValue(): void
    {
        $vault = SecretVault::create($this->masterKey, $this->vaultPath);

        $complexValue = "p@ss'w\"rd&<>{}[]|\\`~!@#\$%^*()";
        $vault->set('COMPLEX', $complexValue);

        self::assertSame($complexValue, $vault->get('COMPLEX'));
    }

    #[Test]
    public function unicodeInSecretValue(): void
    {
        $vault = SecretVault::create($this->masterKey, $this->vaultPath);

        $vault->set('UNICODE', 'Mot de passe: cafe');

        self::assertSame('Mot de passe: cafe', $vault->get('UNICODE'));
    }
}
