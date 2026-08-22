<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\Tests\Unit\Encryption;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Messaging\Encryption\KeyPairGenerator;

use function strlen;

use const SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES;
use const SODIUM_CRYPTO_SIGN_SECRETKEYBYTES;

#[CoversClass(KeyPairGenerator::class)]
final class KeyPairGeneratorTest extends TestCase
{
    public function testGenerateProducesValidKeyPair(): void
    {
        $generator = new KeyPairGenerator();
        $keyPair = $generator->generate();

        self::assertArrayHasKey('publicKey', $keyPair);
        self::assertArrayHasKey('secretKey', $keyPair);
        self::assertSame(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, strlen($keyPair['publicKey']));
        self::assertSame(SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, strlen($keyPair['secretKey']));
    }

    public function testGenerateProducesUniqueKeys(): void
    {
        $generator = new KeyPairGenerator();
        $pair1 = $generator->generate();
        $pair2 = $generator->generate();

        self::assertNotSame($pair1['publicKey'], $pair2['publicKey']);
        self::assertNotSame($pair1['secretKey'], $pair2['secretKey']);
    }
}
