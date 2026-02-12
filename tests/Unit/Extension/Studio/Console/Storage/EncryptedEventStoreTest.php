<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Studio\Console\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\EventEnvelope;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;
use Pulsar\Extension\Studio\Console\Storage\EncryptedEventStore;
use Pulsar\Extension\Studio\Console\Storage\SqliteEventStore;
use Pulsar\Security\Crypto\EncryptorInterface;
use Pulsar\Security\Exception\SecurityException;
use SodiumException;

#[CoversClass(EncryptedEventStore::class)]
final class EncryptedEventStoreTest extends TestCase
{
    private SqliteEventStore $innerStore;
    private EncryptorInterface & Stub $encryptor;
    private EncryptedEventStore $store;

    protected function setUp(): void
    {
        $this->innerStore = SqliteEventStore::inMemory();
        $this->encryptor = $this->createStub(EncryptorInterface::class);
        $this->store = new EncryptedEventStore($this->innerStore, $this->encryptor);
    }

    // --- store() tests ---

    #[Test]
    public function storeEncryptsPayloadBeforePersisting(): void
    {
        $envelope = $this->buildEnvelope('evt-1');
        $plaintext = '{"user":"alice","action":"login"}';
        $ciphertext = 'encrypted:' . $plaintext;

        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->expects(self::once())
            ->method('encrypt')
            ->with($plaintext)
            ->willReturn($ciphertext);

        $store = new EncryptedEventStore($this->innerStore, $encryptor);
        $store->store($envelope, $plaintext);

        // Verify stored payload is the encrypted version
        $row = $this->innerStore->find('evt-1');
        self::assertNotNull($row);
        self::assertSame($ciphertext, $row['payload_json']);
    }

    #[Test]
    public function storeSetsCiphertextHash(): void
    {
        $envelope = $this->buildEnvelope('evt-2');
        $ciphertext = 'encrypted-data';

        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->expects(self::once())
            ->method('encrypt')
            ->willReturn($ciphertext);

        $store = new EncryptedEventStore($this->innerStore, $encryptor);
        $store->store($envelope, '{"test":1}');

        $row = $this->innerStore->find('evt-2');
        self::assertNotNull($row);
        self::assertSame(hash('sha256', $ciphertext), $row['ciphertext_hash']);
    }

    #[Test]
    public function storePassesTenantHashToInner(): void
    {
        $envelope = $this->buildEnvelope('evt-tenant');
        $this->encryptor->method('encrypt')->willReturn('encrypted');

        $this->store->store($envelope, '{}', 'tenant-abc');

        $row = $this->innerStore->find('evt-tenant');
        self::assertNotNull($row);
        self::assertSame('tenant-abc', $row['tenant_hash']);
    }

    #[Test]
    public function storeWithNullTenantHash(): void
    {
        $envelope = $this->buildEnvelope('evt-no-tenant');
        $this->encryptor->method('encrypt')->willReturn('encrypted');

        $this->store->store($envelope, '{}', null);

        $row = $this->innerStore->find('evt-no-tenant');
        self::assertNotNull($row);
        self::assertNull($row['tenant_hash']);
    }

    // --- storeWithChain() tests ---

    #[Test]
    public function storeWithChainEncryptsPayloadAndSetsCiphertextHash(): void
    {
        $envelope = $this->buildEnvelope('evt-chain-1');
        $plaintext = '{"chain":"event"}';
        $ciphertext = 'chain-encrypted';

        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->expects(self::once())
            ->method('encrypt')
            ->with($plaintext)
            ->willReturn($ciphertext);

        $store = new EncryptedEventStore($this->innerStore, $encryptor);
        $store->storeWithChain($envelope, $plaintext, null, null);

        $row = $this->innerStore->find('evt-chain-1');
        self::assertNotNull($row);
        self::assertSame($ciphertext, $row['payload_json']);
        self::assertSame(hash('sha256', $ciphertext), $row['ciphertext_hash']);
    }

    #[Test]
    public function storeWithChainCreatesChainLink(): void
    {
        $envelope = $this->buildEnvelope('evt-chain-link');
        $this->encryptor->method('encrypt')->willReturn('encrypted');

        $this->store->storeWithChain($envelope, '{}', null, null);

        $links = $this->innerStore->chainLinks();
        self::assertCount(1, $links);
        self::assertSame('evt-chain-link', $links[0]['event_id']);
    }

    #[Test]
    public function storeWithChainPassesTenantHashAndMacKey(): void
    {
        $envelope = $this->buildEnvelope('evt-chain-tenant');
        $this->encryptor->method('encrypt')->willReturn('encrypted');

        // storeWithChain with tenant hash and no mac key
        $this->store->storeWithChain($envelope, '{}', 'tenant-xyz', null);

        $row = $this->innerStore->find('evt-chain-tenant');
        self::assertNotNull($row);
        self::assertSame('tenant-xyz', $row['tenant_hash']);
    }

    // --- query() tests ---

    #[Test]
    public function queryDecryptsEncryptedRows(): void
    {
        $envelope = $this->buildEnvelope('evt-query-1');
        $plaintext = '{"decrypted":"payload"}';
        $ciphertext = 'encrypted-query-payload';

        $encryptor = $this->createMock(EncryptorInterface::class);
        // Store with encryption
        $encryptor->expects(self::atLeastOnce())
            ->method('encrypt')
            ->willReturn($ciphertext);

        $encryptor->expects(self::once())
            ->method('decrypt')
            ->with($ciphertext)
            ->willReturn($plaintext);

        $store = new EncryptedEventStore($this->innerStore, $encryptor);
        $store->store($envelope, $plaintext);

        // Query should decrypt
        $rows = $store->query();
        self::assertCount(1, $rows);
        self::assertSame($plaintext, $rows[0]['payload_json']);
    }

    #[Test]
    public function queryPassesFiltersLimitOffset(): void
    {
        // Store multiple events
        for ($i = 1; $i <= 5; $i++) {
            $envelope = $this->buildEnvelope("evt-q-$i", EventType::HttpRequest);
            $this->encryptor->method('encrypt')->willReturn("encrypted-$i");
            $this->store->store($envelope, "{\"n\":$i}");
        }

        // Decrypt stub for all calls
        $this->encryptor->method('decrypt')->willReturn('{"decrypted":true}');

        $rows = $this->store->query([], 2, 0);
        self::assertCount(2, $rows);

        $rows = $this->store->query([], 2, 3);
        self::assertCount(2, $rows);
    }

    #[Test]
    public function queryHandlesDecryptionFailureGracefully(): void
    {
        $envelope = $this->buildEnvelope('evt-fail-decrypt');
        $ciphertext = 'corrupted-ciphertext';

        $this->encryptor->method('encrypt')->willReturn($ciphertext);

        $this->store->store($envelope, '{"secret":"data"}');

        // Decryption fails
        $this->encryptor->method('decrypt')
            ->willThrowException(new SecurityException('Key mismatch'));

        $rows = $this->store->query();
        self::assertCount(1, $rows);
        self::assertSame('{"_decryption_failed":true}', $rows[0]['payload_json']);
    }

    #[Test]
    public function queryHandlesSodiumExceptionGracefully(): void
    {
        $envelope = $this->buildEnvelope('evt-sodium-fail');
        $this->encryptor->method('encrypt')->willReturn('encrypted');

        $this->store->store($envelope, '{}');

        $this->encryptor->method('decrypt')
            ->willThrowException(new SodiumException('bad nonce'));

        $rows = $this->store->query();
        self::assertCount(1, $rows);
        self::assertSame('{"_decryption_failed":true}', $rows[0]['payload_json']);
    }

    #[Test]
    public function querySkipsDecryptionForPlaintextRows(): void
    {
        // Plaintext rows have no ciphertext_hash
        // Insert directly via inner store to simulate pre-encryption data
        $envelope = $this->buildEnvelope('evt-plaintext');
        $this->innerStore->store($envelope, '{"plaintext":"yes"}');

        // decrypt should NOT be called
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->expects(self::never())->method('decrypt');

        $store = new EncryptedEventStore($this->innerStore, $encryptor);
        $rows = $store->query();
        self::assertCount(1, $rows);
        self::assertSame('{"plaintext":"yes"}', $rows[0]['payload_json']);
    }

    // --- find() tests ---

    #[Test]
    public function findDecryptsFoundRow(): void
    {
        $envelope = $this->buildEnvelope('evt-find');
        $ciphertext = 'encrypted-find';
        $plaintext = '{"found":"data"}';

        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('encrypt')->willReturn($ciphertext);
        $encryptor->expects(self::once())
            ->method('decrypt')
            ->with($ciphertext)
            ->willReturn($plaintext);

        $store = new EncryptedEventStore($this->innerStore, $encryptor);
        $store->store($envelope, $plaintext);

        $row = $store->find('evt-find');
        self::assertNotNull($row);
        self::assertSame($plaintext, $row['payload_json']);
    }

    #[Test]
    public function findReturnsNullForMissingEvent(): void
    {
        $result = $this->store->find('nonexistent-id');

        self::assertNull($result);
    }

    #[Test]
    public function findHandlesDecryptionFailureGracefully(): void
    {
        $envelope = $this->buildEnvelope('evt-find-fail');
        $this->encryptor->method('encrypt')->willReturn('encrypted');
        $this->store->store($envelope, '{}');

        $this->encryptor->method('decrypt')
            ->willThrowException(new SecurityException('rotation'));

        $row = $this->store->find('evt-find-fail');
        self::assertNotNull($row);
        self::assertSame('{"_decryption_failed":true}', $row['payload_json']);
    }

    // --- count() tests ---

    #[Test]
    public function countDelegatesToInnerStore(): void
    {
        $this->encryptor->method('encrypt')->willReturn('encrypted');

        $this->store->store($this->buildEnvelope('e1'), '{}');
        $this->store->store($this->buildEnvelope('e2'), '{}');
        $this->store->store($this->buildEnvelope('e3'), '{}');

        self::assertSame(3, $this->store->count());
    }

    #[Test]
    public function countPassesFilters(): void
    {
        $this->encryptor->method('encrypt')->willReturn('encrypted');

        $this->store->store($this->buildEnvelope('e1', EventType::HttpRequest), '{}');
        $this->store->store($this->buildEnvelope('e2', EventType::DatabaseQuery), '{}');

        $count = $this->store->count(['event_type' => 'http.request']);
        self::assertSame(1, $count);
    }

    // --- Delegation tests ---

    #[Test]
    public function sizeInBytesDelegatesToInner(): void
    {
        $size = $this->store->sizeInBytes();

        self::assertGreaterThan(0, $size); // SQLite in-memory DB has nonzero size
    }

    #[Test]
    public function deleteOlderThanDelegatesToInner(): void
    {
        $this->encryptor->method('encrypt')->willReturn('encrypted');

        $this->store->store($this->buildEnvelope('e-old', timestampUs: 1000), '{}');
        $this->store->store($this->buildEnvelope('e-new', timestampUs: 2000000), '{}');

        $deleted = $this->store->deleteOlderThan(1500000);
        self::assertSame(1, $deleted);
    }

    #[Test]
    public function deleteByEventTypesDelegatesToInner(): void
    {
        $this->encryptor->method('encrypt')->willReturn('encrypted');

        $this->store->store($this->buildEnvelope('e1', EventType::HttpRequest), '{}');
        $this->store->store($this->buildEnvelope('e2', EventType::DatabaseQuery), '{}');

        $deleted = $this->store->deleteByEventTypes(['http.request']);
        self::assertSame(1, $deleted);

        self::assertSame(1, $this->store->count());
    }

    #[Test]
    public function deleteByPayloadKeyDelegatesToInner(): void
    {
        $this->encryptor->method('encrypt')->willReturn('{"action":"login"}');

        $this->store->store($this->buildEnvelope('e1', EventType::HttpRequest), '{"action":"login"}');

        // deleteByPayloadKey operates on the stored (encrypted) payload_json
        $deleted = $this->store->deleteByPayloadKey('http.request', '$.action', 'login');
        self::assertSame(1, $deleted);
    }

    #[Test]
    public function clearDelegatesToInner(): void
    {
        $this->encryptor->method('encrypt')->willReturn('encrypted');

        $this->store->store($this->buildEnvelope('e1'), '{}');
        $this->store->store($this->buildEnvelope('e2'), '{}');

        $this->store->clear();

        self::assertSame(0, $this->store->count());
    }

    #[Test]
    public function vacuumDelegatesToInner(): void
    {
        // Should not throw
        $this->store->vacuum();

        // Verify the store is still operational after vacuum
        $this->encryptor->method('encrypt')->willReturn('encrypted');
        $this->store->store($this->buildEnvelope('post-vacuum'), '{}');
        self::assertSame(1, $this->store->count());
    }

    // --- Metadata tests ---

    #[Test]
    public function isEncryptedAlwaysReturnsTrue(): void
    {
        self::assertTrue($this->store->isEncrypted());
    }

    #[Test]
    public function innerReturnsWrappedSqliteStore(): void
    {
        self::assertSame($this->innerStore, $this->store->inner());
    }

    // --- Security: tamper detection ---

    #[Test]
    public function ciphertextHashChangesWithDifferentEncryptedContent(): void
    {
        $callCount = 0;
        $this->encryptor->method('encrypt')
            ->willReturnCallback(function () use (&$callCount): string {
                $callCount++;
                return "encrypted-v$callCount";
            });

        $this->store->store($this->buildEnvelope('e1'), '{"a":1}');
        $this->store->store($this->buildEnvelope('e2'), '{"b":2}');

        $row1 = $this->innerStore->find('e1');
        $row2 = $this->innerStore->find('e2');

        self::assertNotNull($row1);
        self::assertNotNull($row2);
        self::assertNotSame($row1['ciphertext_hash'], $row2['ciphertext_hash']);
    }

    #[Test]
    public function ciphertextHashMatchesSha256OfStoredCiphertext(): void
    {
        $ciphertext = 'specific-encrypted-content-for-hash-verify';
        $this->encryptor->method('encrypt')->willReturn($ciphertext);

        $this->store->store($this->buildEnvelope('e-hash'), '{}');

        $row = $this->innerStore->find('e-hash');
        self::assertNotNull($row);
        self::assertSame(hash('sha256', $ciphertext), $row['ciphertext_hash']);
    }

    // --- Multiple operations sequence ---

    #[Test]
    public function fullEncryptDecryptRoundTrip(): void
    {
        $originalPayload = '{"sensitive":"patient_data","ssn":"123-45-6789"}';
        $encrypted = 'ENC:' . base64_encode($originalPayload);

        $this->encryptor->method('encrypt')->willReturn($encrypted);
        $this->encryptor->method('decrypt')
            ->willReturn($originalPayload);

        $envelope = $this->buildEnvelope('evt-roundtrip');
        $this->store->store($envelope, $originalPayload);

        // Verify stored data is encrypted
        $rawRow = $this->innerStore->find('evt-roundtrip');
        self::assertNotNull($rawRow);
        self::assertSame($encrypted, $rawRow['payload_json']);

        // Verify query returns decrypted data
        $decryptedRow = $this->store->find('evt-roundtrip');
        self::assertNotNull($decryptedRow);
        self::assertSame($originalPayload, $decryptedRow['payload_json']);
    }

    // --- Helper methods ---

    private function buildEnvelope(
        string $eventId,
        EventType $eventType = EventType::HttpRequest,
        int $timestampUs = 1_000_000,
    ): EventEnvelope {
        return new EventEnvelope(
            eventId: $eventId,
            eventType: $eventType,
            schemaVersion: EventVersion::V1,
            timestampUs: $timestampUs,
            requestId: 'req-1',
            traceId: null,
            spanId: null,
            jobId: null,
            appEnv: 'testing',
            hostname: 'localhost',
            payload: [],
            payloadHash: hash('sha256', '{}'),
        );
    }
}
