<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\Tests\Unit\Encryption;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Messaging\Encryption\KeyExchange;

use function strlen;

use const SODIUM_CRYPTO_BOX_PUBLICKEYBYTES;
use const SODIUM_CRYPTO_BOX_SECRETKEYBYTES;
use const SODIUM_CRYPTO_SCALARMULT_BYTES;

#[CoversClass(KeyExchange::class)]
final class KeyExchangeTest extends TestCase
{
    public function testGenerateKeyPairProducesValidLengths(): void
    {
        $exchange = new KeyExchange();
        $pair = $exchange->generateKeyPair();

        self::assertSame(SODIUM_CRYPTO_BOX_PUBLICKEYBYTES, strlen($pair['publicKey']));
        self::assertSame(SODIUM_CRYPTO_BOX_SECRETKEYBYTES, strlen($pair['secretKey']));
    }

    public function testDeriveSharedSecretIsSymmetric(): void
    {
        $exchange = new KeyExchange();

        $alice = $exchange->generateKeyPair();
        $bob = $exchange->generateKeyPair();

        $aliceShared = $exchange->deriveSharedSecret($alice['secretKey'], $bob['publicKey']);
        $bobShared = $exchange->deriveSharedSecret($bob['secretKey'], $alice['publicKey']);

        // Both parties must derive the same shared secret
        self::assertSame($aliceShared, $bobShared);
        self::assertSame(SODIUM_CRYPTO_SCALARMULT_BYTES, strlen($aliceShared));
    }

    public function testDifferentPairsProduceDifferentSecrets(): void
    {
        $exchange = new KeyExchange();

        $alice = $exchange->generateKeyPair();
        $bob = $exchange->generateKeyPair();
        $charlie = $exchange->generateKeyPair();

        $aliceBob = $exchange->deriveSharedSecret($alice['secretKey'], $bob['publicKey']);
        $aliceCharlie = $exchange->deriveSharedSecret($alice['secretKey'], $charlie['publicKey']);

        self::assertNotSame($aliceBob, $aliceCharlie);
    }
}
