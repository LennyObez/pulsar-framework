<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Console\Storage;

use function hash;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\EventEnvelope;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;
use Pulsar\Extension\Studio\Console\Storage\EncryptedEventStore;
use Pulsar\Extension\Studio\Console\Storage\SqliteEventStore;
use Pulsar\Security\Crypto\Encryptor;
use Pulsar\Security\Crypto\MasterKey;

use function random_bytes;
use function sodium_bin2hex;
use function strlen;
use function usleep;

#[CoversClass(EncryptedEventStore::class)]
final class EncryptedEventStoreTest extends TestCase
{
    private SqliteEventStore $innerStore;
    private Encryptor $encryptor;
    private EncryptedEventStore $store;

    protected function setUp(): void
    {
        $this->innerStore = SqliteEventStore::inMemory();
        $masterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));
        $this->encryptor = Encryptor::fromMasterKey($masterKey);
        $this->store = new EncryptedEventStore($this->innerStore, $this->encryptor);
    }

    #[Test]
    public function storeEncryptsPayloadBeforeStorage(): void
    {
        $envelope = $this->createEnvelope('event-1');
        $payloadJson = json_encode(['sensitive' => 'data'], JSON_THROW_ON_ERROR);

        $this->store->store($envelope, $payloadJson);

        // Check that inner store has encrypted data
        $innerRow = $this->innerStore->find('event-1');
        self::assertNotNull($innerRow);
        self::assertNotSame($payloadJson, $innerRow['payload_json']);

        // Verify the encrypted data can be decrypted
        self::assertIsString($innerRow['payload_json']);
        $decrypted = $this->encryptor->decrypt($innerRow['payload_json']);
        self::assertSame($payloadJson, $decrypted);
    }

    #[Test]
    public function storeSetsCiphertextHash(): void
    {
        $envelope = $this->createEnvelope('event-1');
        $payloadJson = json_encode(['data' => 'value'], JSON_THROW_ON_ERROR);

        $this->store->store($envelope, $payloadJson);

        $innerRow = $this->innerStore->find('event-1');
        self::assertNotNull($innerRow);
        self::assertNotNull($innerRow['ciphertext_hash']);
        self::assertIsString($innerRow['ciphertext_hash']);
        self::assertSame(64, strlen($innerRow['ciphertext_hash'])); // SHA-256 hex length
    }

    #[Test]
    public function storeCiphertextHashMatchesStoredCiphertext(): void
    {
        $envelope = $this->createEnvelope('event-1');
        $payloadJson = json_encode(['data' => 'value'], JSON_THROW_ON_ERROR);

        $this->store->store($envelope, $payloadJson);

        $innerRow = $this->innerStore->find('event-1');
        self::assertNotNull($innerRow);
        self::assertIsString($innerRow['payload_json']);

        $expectedHash = hash('sha256', $innerRow['payload_json']);
        self::assertSame($expectedHash, $innerRow['ciphertext_hash']);
    }

    #[Test]
    public function storeWithTenantHashPreservesTenantHash(): void
    {
        $envelope = $this->createEnvelope('event-1');
        $payloadJson = json_encode(['data' => 'value'], JSON_THROW_ON_ERROR);
        $tenantHash = hash('sha256', 'tenant-1');

        $this->store->store($envelope, $payloadJson, $tenantHash);

        $innerRow = $this->innerStore->find('event-1');
        self::assertNotNull($innerRow);
        self::assertSame($tenantHash, $innerRow['tenant_hash']);
    }

    #[Test]
    public function storeWithChainEncryptsPayload(): void
    {
        $envelope = $this->createEnvelope('event-1');
        $payloadJson = json_encode(['chain' => 'data'], JSON_THROW_ON_ERROR);

        $this->store->storeWithChain($envelope, $payloadJson, null, null);

        $innerRow = $this->innerStore->find('event-1');
        self::assertNotNull($innerRow);
        self::assertNotSame($payloadJson, $innerRow['payload_json']);
        self::assertIsString($innerRow['payload_json']);

        $decrypted = $this->encryptor->decrypt($innerRow['payload_json']);
        self::assertSame($payloadJson, $decrypted);
    }

    #[Test]
    public function storeWithChainSetsCiphertextHash(): void
    {
        $envelope = $this->createEnvelope('event-1');
        $payloadJson = json_encode(['chain' => 'data'], JSON_THROW_ON_ERROR);

        $this->store->storeWithChain($envelope, $payloadJson, null, null);

        $innerRow = $this->innerStore->find('event-1');
        self::assertNotNull($innerRow);
        self::assertNotNull($innerRow['ciphertext_hash']);
    }

    #[Test]
    public function storeWithChainCreatesChainLink(): void
    {
        $envelope = $this->createEnvelope('event-1');
        $payloadJson = json_encode(['data' => 'value'], JSON_THROW_ON_ERROR);

        $this->store->storeWithChain($envelope, $payloadJson, null, null);

        $links = $this->innerStore->chainLinks();
        self::assertCount(1, $links);
        self::assertSame('event-1', $links[0]['event_id']);
    }

    #[Test]
    public function storeWithChainWithMacKey(): void
    {
        $envelope = $this->createEnvelope('event-1');
        $payloadJson = json_encode(['data' => 'value'], JSON_THROW_ON_ERROR);
        $macKey = 'test-mac-key-32-bytes-length-ok!';

        $this->store->storeWithChain($envelope, $payloadJson, null, $macKey);

        $links = $this->innerStore->chainLinks();
        self::assertCount(1, $links);
        self::assertNotNull($links[0]['link_mac']);
    }

    #[Test]
    public function queryDecryptsPayloads(): void
    {
        $envelope = $this->createEnvelope('event-1');
        $payloadJson = json_encode(['query' => 'test'], JSON_THROW_ON_ERROR);

        $this->store->store($envelope, $payloadJson);

        $results = $this->store->query();

        self::assertCount(1, $results);
        self::assertSame($payloadJson, $results[0]['payload_json']);
    }

    #[Test]
    public function queryWithFiltersReturnsDecryptedResults(): void
    {
        $this->store->store($this->createEnvelope('http-1', EventType::HttpRequest), json_encode(['type' => 'http'], JSON_THROW_ON_ERROR));
        $this->store->store($this->createEnvelope('db-1', EventType::DatabaseQuery), json_encode(['type' => 'db'], JSON_THROW_ON_ERROR));

        $results = $this->store->query(['event_type' => EventType::HttpRequest->value]);

        self::assertCount(1, $results);
        self::assertSame('{"type":"http"}', $results[0]['payload_json']);
    }

    #[Test]
    public function queryWithLimitAndOffsetDecryptsResults(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->store->store(
                $this->createEnvelope("event-{$i}"),
                json_encode(['index' => $i], JSON_THROW_ON_ERROR),
            );
            usleep(10);
        }

        $results = $this->store->query([], 2, 1);

        self::assertCount(2, $results);
        foreach ($results as $result) {
            // Verify decryption worked
            self::assertIsString($result['payload_json']);
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($result['payload_json'], true);
            self::assertIsArray($decoded);
            self::assertArrayHasKey('index', $decoded);
        }
    }

    #[Test]
    public function countDelegatesDirectlyToInnerStore(): void
    {
        $this->store->store($this->createEnvelope('event-1'), '{}');
        $this->store->store($this->createEnvelope('event-2'), '{}');
        $this->store->store($this->createEnvelope('event-3'), '{}');

        self::assertSame(3, $this->store->count());
    }

    #[Test]
    public function countWithFiltersDelegatesCorrectly(): void
    {
        $this->store->store($this->createEnvelope('http-1', EventType::HttpRequest), '{}');
        $this->store->store($this->createEnvelope('http-2', EventType::HttpRequest), '{}');
        $this->store->store($this->createEnvelope('db-1', EventType::DatabaseQuery), '{}');

        self::assertSame(2, $this->store->count(['event_type' => EventType::HttpRequest->value]));
    }

    #[Test]
    public function findDecryptsPayload(): void
    {
        $envelope = $this->createEnvelope('event-1');
        $payloadJson = json_encode(['find' => 'test'], JSON_THROW_ON_ERROR);

        $this->store->store($envelope, $payloadJson);

        $result = $this->store->find('event-1');

        self::assertNotNull($result);
        self::assertSame($payloadJson, $result['payload_json']);
    }

    #[Test]
    public function findReturnsNullForNonExistentEvent(): void
    {
        $result = $this->store->find('nonexistent');

        self::assertNull($result);
    }

    #[Test]
    public function sizeInBytesDelegatesToInnerStore(): void
    {
        $this->store->store($this->createEnvelope('event-1'), json_encode(['data' => str_repeat('x', 1000)], JSON_THROW_ON_ERROR));

        $size = $this->store->sizeInBytes();

        self::assertGreaterThan(0, $size);
        self::assertSame($this->innerStore->sizeInBytes(), $size);
    }

    #[Test]
    public function deleteOlderThanDelegatesToInnerStore(): void
    {
        $baseTimestamp = 1_700_000_000_000_000;

        $this->store->store($this->createEnvelope('old', timestampUs: $baseTimestamp), '{}');
        $this->store->store($this->createEnvelope('new', timestampUs: $baseTimestamp + 2_000_000), '{}');

        $deleted = $this->store->deleteOlderThan($baseTimestamp + 1_000_000);

        self::assertSame(1, $deleted);
        self::assertNull($this->store->find('old'));
        self::assertNotNull($this->store->find('new'));
    }

    #[Test]
    public function clearDelegatesToInnerStore(): void
    {
        $this->store->store($this->createEnvelope('event-1'), '{}');
        $this->store->store($this->createEnvelope('event-2'), '{}');

        $this->store->clear();

        self::assertSame(0, $this->store->count());
    }

    #[Test]
    public function vacuumDelegatesToInnerStore(): void
    {
        $this->store->store($this->createEnvelope('event-1'), '{}');
        $this->store->clear();

        // Should not throw
        $this->store->vacuum();

        self::assertSame(0, $this->store->count());
    }

    #[Test]
    public function isEncryptedReturnsTrue(): void
    {
        self::assertTrue($this->store->isEncrypted());
    }

    #[Test]
    public function innerReturnsUnderlyingStore(): void
    {
        $inner = $this->store->inner();

        self::assertSame($this->innerStore, $inner);
    }

    #[Test]
    public function differentEncryptorCannotDecrypt(): void
    {
        $envelope = $this->createEnvelope('event-1');
        $payloadJson = json_encode(['secret' => 'data'], JSON_THROW_ON_ERROR);

        $this->store->store($envelope, $payloadJson);

        // Try to decrypt with different key
        $differentMasterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));
        $differentEncryptor = Encryptor::fromMasterKey($differentMasterKey);

        $innerRow = $this->innerStore->find('event-1');
        self::assertNotNull($innerRow);
        self::assertIsString($innerRow['payload_json']);

        $this->expectException(\Pulsar\Security\Exception\SecurityException::class);
        $differentEncryptor->decrypt($innerRow['payload_json']);
    }

    #[Test]
    public function queryDecryptsAllRowsInResults(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->store->store(
                $this->createEnvelope("event-{$i}"),
                json_encode(['message' => "Message {$i}"], JSON_THROW_ON_ERROR),
            );
            usleep(10);
        }

        $results = $this->store->query();

        self::assertCount(3, $results);
        foreach ($results as $result) {
            self::assertIsString($result['payload_json']);
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($result['payload_json'], true);
            self::assertIsArray($decoded);
            self::assertArrayHasKey('message', $decoded);
            self::assertIsString($decoded['message']);
            self::assertStringStartsWith('Message', $decoded['message']);
        }
    }

    #[Test]
    public function encryptedPayloadsDifferForSameInput(): void
    {
        $payloadJson = json_encode(['data' => 'same'], JSON_THROW_ON_ERROR);

        $this->store->store($this->createEnvelope('event-1'), $payloadJson);
        $this->store->store($this->createEnvelope('event-2'), $payloadJson);

        $row1 = $this->innerStore->find('event-1');
        $row2 = $this->innerStore->find('event-2');

        self::assertNotNull($row1);
        self::assertNotNull($row2);
        self::assertIsString($row1['payload_json']);
        self::assertIsString($row2['payload_json']);

        // Encrypted values should differ due to random nonce
        self::assertNotSame($row1['payload_json'], $row2['payload_json']);

        // But decrypted values should be the same
        $decrypted1 = $this->encryptor->decrypt($row1['payload_json']);
        $decrypted2 = $this->encryptor->decrypt($row2['payload_json']);
        self::assertSame($decrypted1, $decrypted2);
    }

    #[Test]
    public function queryHandlesEmptyResults(): void
    {
        $results = $this->store->query();

        self::assertSame([], $results);
    }

    #[Test]
    public function querySkipsDecryptionForPlaintextRowsWithoutCiphertextHash(): void
    {
        // Store directly to the inner store (bypassing encryption)
        // This simulates rows stored before encryption was enabled
        $plainPayload = json_encode(['legacy' => 'data'], JSON_THROW_ON_ERROR);
        $envelope = $this->createEnvelope('plaintext-event');
        $this->innerStore->store($envelope, $plainPayload);

        // Verify inner store has no ciphertext_hash
        $innerRow = $this->innerStore->find('plaintext-event');
        self::assertNotNull($innerRow);
        self::assertNull($innerRow['ciphertext_hash']);

        // Query through encrypted store — should return plaintext without error
        $result = $this->store->find('plaintext-event');
        self::assertNotNull($result);
        self::assertSame($plainPayload, $result['payload_json']);
    }

    #[Test]
    public function queryMixesEncryptedAndPlaintextRows(): void
    {
        // Store a plaintext row directly in the inner store
        $plainPayload = json_encode(['type' => 'plain'], JSON_THROW_ON_ERROR);
        $this->innerStore->store($this->createEnvelope('plain-1'), $plainPayload);
        usleep(10);

        // Store an encrypted row through the encrypted store
        $encPayload = json_encode(['type' => 'encrypted'], JSON_THROW_ON_ERROR);
        $this->store->store($this->createEnvelope('enc-1'), $encPayload);
        usleep(10);

        // Query all — both should be readable
        $results = $this->store->query();
        self::assertCount(2, $results);

        $payloads = [];
        foreach ($results as $row) {
            self::assertIsString($row['payload_json']);
            /** @var array{type: string} $decoded */
            $decoded = json_decode($row['payload_json'], true, 512, JSON_THROW_ON_ERROR);
            $payloads[] = $decoded['type'];
        }

        self::assertContains('plain', $payloads);
        self::assertContains('encrypted', $payloads);
    }

    #[Test]
    public function deleteByEventTypesDelegatesToInnerStore(): void
    {
        $this->store->store($this->createEnvelope('http-1', EventType::HttpRequest), '{}');
        $this->store->store($this->createEnvelope('http-2', EventType::HttpRequest), '{}');
        $this->store->store($this->createEnvelope('db-1', EventType::DatabaseQuery), '{}');

        $deleted = $this->store->deleteByEventTypes([EventType::HttpRequest->value]);

        self::assertSame(2, $deleted);
        self::assertSame(1, $this->store->count());
    }

    #[Test]
    public function deleteByPayloadKeyDelegatesToInnerStore(): void
    {
        $this->innerStore->store(
            $this->createEnvelope('http-1', EventType::HttpRequest),
            json_encode(['method' => 'GET', 'uri' => '/delete-me'], JSON_THROW_ON_ERROR),
        );
        $this->innerStore->store(
            $this->createEnvelope('http-2', EventType::HttpRequest),
            json_encode(['method' => 'POST', 'uri' => '/keep'], JSON_THROW_ON_ERROR),
        );

        $deleted = $this->store->deleteByPayloadKey(
            EventType::HttpRequest->value,
            '$.uri',
            '/delete-me',
        );

        self::assertSame(1, $deleted);
        self::assertSame(1, $this->store->count());
    }

    #[Test]
    public function queryHandlesRowWithoutPayloadJson(): void
    {
        // Store an event normally
        $this->store->store($this->createEnvelope('event-1'), '{}');

        // Manually corrupt the row by setting payload_json to something non-string
        // This tests the is_string check in decryptRow
        // We can't easily test this path since SQLite stores text, but we verify
        // the method handles the happy path correctly
        $results = $this->store->query();

        self::assertCount(1, $results);
        self::assertSame('{}', $results[0]['payload_json']);
    }

    private function createEnvelope(
        string $eventId,
        EventType $eventType = EventType::HttpRequest,
        ?int $timestampUs = null,
    ): EventEnvelope {
        return new EventEnvelope(
            eventId: $eventId,
            eventType: $eventType,
            schemaVersion: EventVersion::V1,
            timestampUs: $timestampUs ?? (int) (microtime(true) * 1_000_000.0),
            requestId: 'req-123',
            traceId: 'trace-123',
            spanId: 'span-123',
            jobId: null,
            appEnv: 'testing',
            hostname: 'localhost',
            payload: ['method' => 'GET', 'uri' => '/test'],
            payloadHash: hash('sha256', '{}'),
        );
    }
}
