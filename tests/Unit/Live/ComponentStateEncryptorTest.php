<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Live;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Live\Internal\ComponentStateEncryptor;
use Pulsar\Security\Crypto\EncryptorInterface;

#[CoversClass(ComponentStateEncryptor::class)]
final class ComponentStateEncryptorTest extends TestCase
{
    #[Test]
    public function encryptAndDecryptRoundTrip(): void
    {
        $encryptor = $this->createStub(EncryptorInterface::class);
        $encryptor->method('encrypt')->willReturnCallback(static fn(string $data): string => 'ENC:' . $data);
        $encryptor->method('decrypt')->willReturnCallback(static function (string $data): ?string {
            if (str_starts_with($data, 'ENC:')) {
                return substr($data, 4);
            }

            return null;
        });

        $stateEncryptor = new ComponentStateEncryptor($encryptor);

        $encrypted = $stateEncryptor->encrypt('App\\Counter', ['count' => 5]);
        self::assertNotEmpty($encrypted);

        $decrypted = $stateEncryptor->decrypt($encrypted);
        self::assertNotNull($decrypted);
        self::assertSame('App\\Counter', $decrypted['component']);
        self::assertSame(['count' => 5], $decrypted['state']);
    }

    #[Test]
    public function decryptReturnsNullForInvalidBase64(): void
    {
        $encryptor = $this->createStub(EncryptorInterface::class);
        $stateEncryptor = new ComponentStateEncryptor($encryptor);

        // The @ character is invalid base64 in strict mode
        self::assertNull($stateEncryptor->decrypt('!!!invalid-base64!!!'));
    }

    #[Test]
    public function decryptReturnsNullWhenEncryptorThrowsOnInvalidData(): void
    {
        $encryptor = $this->createStub(EncryptorInterface::class);
        // Return empty string to simulate garbage data that fails json_decode
        $encryptor->method('decrypt')->willReturn('not-json');

        $stateEncryptor = new ComponentStateEncryptor($encryptor);

        // Valid base64, but decrypted plaintext is not valid JSON with required fields
        self::assertNull($stateEncryptor->decrypt(base64_encode('garbage')));
    }

    #[Test]
    public function decryptReturnsNullWhenPayloadMissingFields(): void
    {
        $encryptor = $this->createStub(EncryptorInterface::class);
        $encryptor->method('decrypt')->willReturn('{"component":"X"}');

        $stateEncryptor = new ComponentStateEncryptor($encryptor);

        self::assertNull($stateEncryptor->decrypt(base64_encode('x')));
    }

    #[Test]
    public function decryptReturnsNullOnChecksumMismatch(): void
    {
        $encryptor = $this->createStub(EncryptorInterface::class);
        $encryptor->method('decrypt')->willReturn(json_encode([
            'component' => 'App\\Foo',
            'state' => ['a' => 1],
            'checksum' => 'wrong-checksum-value',
        ]));

        $stateEncryptor = new ComponentStateEncryptor($encryptor);

        self::assertNull($stateEncryptor->decrypt(base64_encode('x')));
    }
}
