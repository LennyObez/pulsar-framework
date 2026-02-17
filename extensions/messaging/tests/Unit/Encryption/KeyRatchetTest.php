<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\Tests\Unit\Encryption;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Messaging\Encryption\KeyRatchet;
use Pulsar\Extension\Messaging\Encryption\MessageEncryptor;

use function strlen;

use const SODIUM_CRYPTO_SECRETBOX_KEYBYTES;

#[CoversClass(KeyRatchet::class)]
final class KeyRatchetTest extends TestCase
{
    public function testAdvanceProducesValidMessageKey(): void
    {
        $ratchet = new KeyRatchet(random_bytes(32));
        $result = $ratchet->advance();

        self::assertArrayHasKey('messageKey', $result);
        self::assertArrayHasKey('counter', $result);
        self::assertSame(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, strlen($result['messageKey']));
        self::assertSame(0, $result['counter']);
    }

    public function testAdvanceIncrementsCounter(): void
    {
        $ratchet = new KeyRatchet(random_bytes(32));

        $r1 = $ratchet->advance();
        $r2 = $ratchet->advance();
        $r3 = $ratchet->advance();

        self::assertSame(0, $r1['counter']);
        self::assertSame(1, $r2['counter']);
        self::assertSame(2, $r3['counter']);
        self::assertSame(3, $ratchet->counter());
    }

    public function testAdvanceProducesUniqueKeys(): void
    {
        $ratchet = new KeyRatchet(random_bytes(32));

        $keys = [];
        for ($i = 0; $i < 10; $i++) {
            $result = $ratchet->advance();
            $keys[] = $result['messageKey'];
        }

        // All keys must be unique
        self::assertCount(10, array_unique($keys, SORT_REGULAR));
    }

    public function testSameInitialSecretProducesSameSequence(): void
    {
        $secret = random_bytes(32);

        $ratchet1 = new KeyRatchet($secret);
        $ratchet2 = new KeyRatchet($secret);

        for ($i = 0; $i < 5; $i++) {
            $r1 = $ratchet1->advance();
            $r2 = $ratchet2->advance();

            self::assertSame($r1['messageKey'], $r2['messageKey'], "Step $i keys should match");
        }
    }

    public function testDifferentSecretsProduceDifferentSequences(): void
    {
        $ratchet1 = new KeyRatchet(random_bytes(32));
        $ratchet2 = new KeyRatchet(random_bytes(32));

        $r1 = $ratchet1->advance();
        $r2 = $ratchet2->advance();

        self::assertNotSame($r1['messageKey'], $r2['messageKey']);
    }

    public function testResetChangesSequence(): void
    {
        $ratchet = new KeyRatchet(random_bytes(32));

        $before = $ratchet->advance();
        self::assertSame(1, $ratchet->counter());

        $ratchet->reset(random_bytes(32));

        self::assertSame(0, $ratchet->counter());

        $after = $ratchet->advance();
        self::assertNotSame($before['messageKey'], $after['messageKey']);
    }

    public function testDestroyResetsState(): void
    {
        $ratchet = new KeyRatchet(random_bytes(32));
        $ratchet->advance();
        $ratchet->advance();

        $ratchet->destroy();

        self::assertSame(0, $ratchet->counter());
    }

    public function testMessageKeysWorkWithEncryptor(): void
    {
        $ratchet = new KeyRatchet(random_bytes(32));
        $encryptor = new MessageEncryptor();

        $result = $ratchet->advance();
        $key = $result['messageKey'];

        $encrypted = $encryptor->encrypt('Forward-secret message', $key);
        $decrypted = $encryptor->decrypt($encrypted['ciphertext'], $encrypted['nonce'], $key);

        self::assertSame('Forward-secret message', $decrypted);
    }
}
