<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Console\Storage;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\ConsoleEvent;
use Pulsar\Extension\Studio\Console\Event\EventEnvelope;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;
use Pulsar\Extension\Studio\Console\Storage\EncryptedEventStore;
use Pulsar\Extension\Studio\Console\Storage\SqliteEventStore;
use Pulsar\Observability\Context\CorrelationContext;
use Pulsar\Security\Crypto\EncryptorInterface;

use function hash;

#[CoversClass(EncryptedEventStore::class)]
final class EncryptedEventStoreAtomicTest extends TestCase
{
    private SqliteEventStore $inner;
    private EncryptedEventStore $store;

    protected function setUp(): void
    {
        $this->inner = SqliteEventStore::inMemory();

        $encryptor = $this->createStub(EncryptorInterface::class);
        $encryptor->method('encrypt')->willReturnCallback(
            static fn(string $plaintext): string => 'ENC:' . $plaintext,
        );
        $encryptor->method('decrypt')->willReturnCallback(
            static fn(string $ciphertext): string => substr($ciphertext, 4),
        );

        $this->store = new EncryptedEventStore($this->inner, $encryptor);
    }

    #[Test]
    public function store_sets_ciphertext_hash_atomically(): void
    {
        $envelope = $this->createEnvelope();

        $this->store->store($envelope, '{"test":"data"}');

        $row = $this->inner->find($envelope->eventId);
        self::assertNotNull($row);

        // ciphertext_hash must be set in the same transaction as the insert
        self::assertNotNull($row['ciphertext_hash']);
        self::assertSame(hash('sha256', 'ENC:{"test":"data"}'), $row['ciphertext_hash']);
    }

    #[Test]
    public function storeWithChain_sets_ciphertext_hash_atomically(): void
    {
        $envelope = $this->createEnvelope();

        $this->store->storeWithChain($envelope, '{"chain":"data"}', null, null);

        $row = $this->inner->find($envelope->eventId);
        self::assertNotNull($row);

        // ciphertext_hash must be set atomically within the chain transaction
        self::assertNotNull($row['ciphertext_hash']);
        self::assertSame(hash('sha256', 'ENC:{"chain":"data"}'), $row['ciphertext_hash']);
    }

    #[Test]
    public function storeWithChain_ciphertext_hash_matches_encrypted_payload(): void
    {
        $envelope = $this->createEnvelope();
        $payload = '{"integrity":"check"}';

        $this->store->storeWithChain($envelope, $payload, null, null);

        $row = $this->inner->find($envelope->eventId);
        self::assertNotNull($row);

        // The stored payload should be encrypted
        self::assertSame('ENC:' . $payload, $row['payload_json']);

        // And ciphertext_hash should match the encrypted payload
        self::assertSame(hash('sha256', $row['payload_json']), $row['ciphertext_hash']);
    }

    #[Test]
    public function store_without_encryption_has_no_ciphertext_hash_on_inner(): void
    {
        $envelope = $this->createEnvelope();

        // Store directly on inner (no encryption)
        $this->inner->store($envelope, '{"plain":"data"}');

        $row = $this->inner->find($envelope->eventId);
        self::assertNotNull($row);
        self::assertNull($row['ciphertext_hash']);
    }

    private function createEnvelope(): EventEnvelope
    {
        $event = new class implements ConsoleEvent {
            #[Override]
            public function eventType(): EventType
            {
                return EventType::LogEntry;
            }

            #[Override]
            public function schemaVersion(): EventVersion
            {
                return EventVersion::V1;
            }

            #[Override]
            public function toArray(): array
            {
                return ['level' => 'info', 'message' => 'test'];
            }
        };

        return EventEnvelope::wrap(
            $event,
            new CorrelationContext(requestId: 'req-1'),
            'local',
            'test-host',
        );
    }
}
