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
use Pulsar\Security\Crypto\KeyProviderInterface;

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

        $keyProvider = $this->createStub(KeyProviderInterface::class);

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
}
