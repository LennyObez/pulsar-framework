<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Orm\Features\Encryption;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Config\EncryptionConfig;
use Pulsar\Extension\Orm\Features\Encryption\AttributeColumnEncryptor;
use Pulsar\Security\Crypto\EncryptorInterface;
use Pulsar\Security\Crypto\MasterKey;

use function sodium_bin2hex;
use function strlen;

#[CoversClass(AttributeColumnEncryptor::class)]
final class AttributeColumnEncryptorTest extends TestCase
{
    private AttributeColumnEncryptor $encryptor;
    /** @var EncryptorInterface&Stub */
    private EncryptorInterface $derivedEncryptor;

    protected function setUp(): void
    {
        $this->derivedEncryptor = $this->createStub(EncryptorInterface::class);

        /** @var EncryptorInterface&Stub $baseEncryptor */
        $baseEncryptor = $this->createStub(EncryptorInterface::class);
        $baseEncryptor->method('withDerivedKey')
            ->willReturn($this->derivedEncryptor);

        $keyProvider = MasterKey::fromHex(sodium_bin2hex(str_repeat("\x01", SODIUM_CRYPTO_KDF_KEYBYTES)));

        $config = new EncryptionConfig(
            enabled: true,
            subKeyId: 5,
            context: 'orm__enc',
            blindIndexContext: str_repeat("\x00", 32),
        );

        $this->encryptor = new AttributeColumnEncryptor($baseEncryptor, $keyProvider, $config);
    }

    #[Test]
    public function encryptDelegatesToDerivedEncryptor(): void
    {
        $this->derivedEncryptor->method('encrypt')
            ->willReturn('encrypted_data');

        $result = $this->encryptor->encrypt('plaintext');

        self::assertSame('encrypted_data', $result);
    }

    #[Test]
    public function decryptDelegatesToDerivedEncryptor(): void
    {
        $this->derivedEncryptor->method('decrypt')
            ->willReturn('original_text');

        $result = $this->encryptor->decrypt('encrypted_data');

        self::assertSame('original_text', $result);
    }

    #[Test]
    public function blindIndexReturnsDeterministicHash(): void
    {
        $result1 = $this->encryptor->blindIndex('test@example.com');
        $result2 = $this->encryptor->blindIndex('test@example.com');

        self::assertSame($result1, $result2);
        self::assertSame(32, strlen($result1));
    }

    #[Test]
    public function blindIndexWithCustomLength(): void
    {
        $result = $this->encryptor->blindIndex('test@example.com', 16);

        self::assertSame(16, strlen($result));
    }

    #[Test]
    public function blindIndexDiffersForDifferentInputs(): void
    {
        $result1 = $this->encryptor->blindIndex('alice@example.com');
        $result2 = $this->encryptor->blindIndex('bob@example.com');

        self::assertNotSame($result1, $result2);
    }

    #[Test]
    public function blindIndexIsKeyedByTheMasterKeyNotAPublicConstant(): void
    {
        // C10 (known-answer): two deployments with different master keys must
        // produce different blind indexes for the same plaintext. With the old
        // public-constant key they were identical, so a DB-dump attacker could
        // precompute/confirm the indexed PII offline.
        $a = $this->encryptorWith(str_repeat("\x01", SODIUM_CRYPTO_KDF_KEYBYTES));
        $b = $this->encryptorWith(str_repeat("\x02", SODIUM_CRYPTO_KDF_KEYBYTES));

        self::assertNotSame(
            $a->blindIndex('test@example.com'),
            $b->blindIndex('test@example.com'),
            'blind index must be keyed by the master key, not a public constant',
        );
    }

    #[Test]
    public function blindIndexIsDomainSeparatedByContext(): void
    {
        $key = str_repeat("\x01", SODIUM_CRYPTO_KDF_KEYBYTES);
        $a = $this->encryptorWith($key, str_repeat("\x00", 32));
        $b = $this->encryptorWith($key, str_repeat("\x0f", 32));

        self::assertNotSame(
            $a->blindIndex('test@example.com'),
            $b->blindIndex('test@example.com'),
            'a different blind-index context must derive a different key',
        );
    }

    private function encryptorWith(string $rawMasterKey, string $blindIndexContext = ''): AttributeColumnEncryptor
    {
        if ($blindIndexContext === '') {
            $blindIndexContext = str_repeat("\x00", 32);
        }

        /** @var EncryptorInterface&Stub $baseEncryptor */
        $baseEncryptor = $this->createStub(EncryptorInterface::class);
        $baseEncryptor->method('withDerivedKey')->willReturn($this->createStub(EncryptorInterface::class));

        $keyProvider = MasterKey::fromHex(sodium_bin2hex($rawMasterKey));

        $config = new EncryptionConfig(
            enabled: true,
            subKeyId: 5,
            context: 'orm__enc',
            blindIndexContext: $blindIndexContext,
        );

        return new AttributeColumnEncryptor($baseEncryptor, $keyProvider, $config);
    }
}
