<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\Tests\Unit\Encryption;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Messaging\Encryption\GroupKeyDistributor;
use Pulsar\Extension\Messaging\Encryption\KeyExchange;
use Pulsar\Extension\Messaging\Encryption\MessageEncryptor;

#[CoversClass(GroupKeyDistributor::class)]
final class GroupKeyDistributorTest extends TestCase
{
    public function testEncryptAndDecryptGroupKey(): void
    {
        $distributor = new GroupKeyDistributor();
        $keyExchange = new KeyExchange();
        $encryptor = new MessageEncryptor();

        $groupKey = $encryptor->generateKey();
        $sender = $keyExchange->generateKeyPair();
        $recipient = $keyExchange->generateKeyPair();

        // Sender encrypts group key for recipient
        $result = $distributor->encryptForRecipient(
            $groupKey,
            $sender['secretKey'],
            $recipient['publicKey'],
        );

        self::assertArrayHasKey('encryptedKey', $result);
        self::assertArrayHasKey('nonce', $result);

        // Recipient decrypts group key
        $decrypted = $distributor->decryptGroupKey(
            $result['encryptedKey'],
            $result['nonce'],
            $recipient['secretKey'],
            $sender['publicKey'],
        );

        self::assertSame($groupKey, $decrypted);
    }

    public function testDecryptWithWrongKeyReturnsFalse(): void
    {
        $distributor = new GroupKeyDistributor();
        $keyExchange = new KeyExchange();

        $groupKey = random_bytes(32);
        $sender = $keyExchange->generateKeyPair();
        $recipient = $keyExchange->generateKeyPair();
        $attacker = $keyExchange->generateKeyPair();

        $result = $distributor->encryptForRecipient(
            $groupKey,
            $sender['secretKey'],
            $recipient['publicKey'],
        );

        // Attacker cannot decrypt with their own key
        $decrypted = $distributor->decryptGroupKey(
            $result['encryptedKey'],
            $result['nonce'],
            $attacker['secretKey'],
            $sender['publicKey'],
        );

        self::assertFalse($decrypted);
    }

    public function testDistributeToAll(): void
    {
        $distributor = new GroupKeyDistributor();
        $keyExchange = new KeyExchange();

        $groupKey = random_bytes(32);
        $sender = $keyExchange->generateKeyPair();

        $recipients = [];
        $publicKeys = [];
        for ($i = 0; $i < 3; $i++) {
            $pair = $keyExchange->generateKeyPair();
            $recipients["user-$i"] = $pair;
            $publicKeys["user-$i"] = $pair['publicKey'];
        }

        $distributed = $distributor->distributeToAll($groupKey, $sender['secretKey'], $publicKeys);

        self::assertCount(3, $distributed);

        // Each recipient can decrypt the group key
        foreach ($recipients as $userId => $pair) {
            $blob = $distributed[$userId];
            $decrypted = $distributor->decryptGroupKey(
                $blob['encryptedKey'],
                $blob['nonce'],
                $pair['secretKey'],
                $sender['publicKey'],
            );

            self::assertSame($groupKey, $decrypted, "User $userId should decrypt the group key");
        }
    }
}
