<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Console\Storage;

use JsonException;
use Override;
use PDOException;
use Pulsar\Api\Internal;
use Pulsar\Extension\Studio\Console\Event\EventEnvelope;
use Pulsar\Extension\Studio\Exception\StudioException;
use Pulsar\Security\Crypto\EncryptorInterface;
use Pulsar\Security\Exception\SecurityException;
use Random\RandomException;
use SodiumException;
use Throwable;

use function array_map;
use function hash;
use function is_string;

/**
 * Decorator that encrypts event payloads before storage.
 *
 * Preserves the original payload_hash (computed from redacted plaintext
 * before encryption) for chain verification without decryption keys.
 * Stores an additional ciphertext_hash for DB-level tamper detection.
 */
#[Internal]
final readonly class EncryptedEventStore implements EventStoreInterface
{
    public function __construct(
        private SqliteEventStore $inner,
        private EncryptorInterface $encryptor,
    ) {}

    /**
     * @throws SecurityException If encryption fails
     * @throws RandomException
     * @throws SodiumException
     */
    #[Override]
    public function store(EventEnvelope $envelope, string $payloadJson, ?string $tenantHash = null): void
    {
        $encrypted = $this->encryptor->encrypt($payloadJson);
        $ciphertextHash = hash('sha256', $encrypted);

        // Store event with encrypted payload and ciphertext_hash atomically
        $pdo = $this->inner->pdo();
        $pdo->beginTransaction();

        try {
            $this->inner->store($envelope, $encrypted, $tenantHash);

            $stmt = $pdo->prepare('UPDATE studio_events SET ciphertext_hash = :hash WHERE event_id = :id');
            $stmt->execute(['hash' => $ciphertextHash, 'id' => $envelope->eventId]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Store with chain link, encrypting the payload.
     *
     * Computes ciphertext_hash before storeWithChain() and passes it as a parameter
     * so it is set in the same transaction, ensuring atomicity.
     *
     * @throws JsonException If JSON encoding fails
     * @throws PDOException If a non-retryable database error occurs
     * @throws SecurityException If encryption fails
     * @throws StudioException If maximum retry attempts exceeded due to database busy
     * @throws RandomException
     * @throws SodiumException
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function storeWithChain(
        EventEnvelope $envelope,
        string $payloadJson,
        ?string $tenantHash,
        ?string $chainMacKey,
    ): void {
        $encrypted = $this->encryptor->encrypt($payloadJson);
        $ciphertextHash = hash('sha256', $encrypted);

        // storeWithChain runs in a BEGIN IMMEDIATE transaction internally.
        // We pass the ciphertext_hash so it's set atomically in the same transaction.
        $this->inner->storeWithChain($envelope, $encrypted, $tenantHash, $chainMacKey, $ciphertextHash);
    }

    #[Override]
    public function query(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $rows = $this->inner->query($filters, $limit, $offset);

        return array_map(fn(array $row): array => $this->decryptRow($row), $rows);
    }

    #[Override]
    public function count(array $filters = []): int
    {
        return $this->inner->count($filters);
    }

    #[Override]
    public function find(string $eventId): ?array
    {
        $row = $this->inner->find($eventId);

        return $row !== null ? $this->decryptRow($row) : null;
    }

    #[Override]
    public function sizeInBytes(): int
    {
        return $this->inner->sizeInBytes();
    }

    #[Override]
    public function deleteOlderThan(int $timestampUs): int
    {
        return $this->inner->deleteOlderThan($timestampUs);
    }

    #[Override]
    public function deleteByEventTypes(array $eventTypes): int
    {
        return $this->inner->deleteByEventTypes($eventTypes);
    }

    #[Override]
    public function deleteByPayloadKey(string $eventType, string $jsonPath, string $value): int
    {
        return $this->inner->deleteByPayloadKey($eventType, $jsonPath, $value);
    }

    #[Override]
    public function clear(): void
    {
        $this->inner->clear();
    }

    #[Override]
    public function vacuum(): void
    {
        $this->inner->vacuum();
    }

    /**
     * Whether encryption is active.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function isEncrypted(): bool
    {
        return true;
    }

    /**
     * Get the underlying store.
     */
    public function inner(): SqliteEventStore
    {
        return $this->inner;
    }

    /**
     * Decrypt the payload_json field of a row.
     *
     * Gracefully handles undecryptable rows (key rotation, dev session changes)
     * by returning a fallback marker instead of throwing.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function decryptRow(array $row): array
    {
        // Skip decryption for plaintext rows (stored before encryption was enabled).
        // Encrypted rows always have a ciphertext_hash; plaintext rows have NULL.
        if (isset($row['payload_json']) && is_string($row['payload_json']) && isset($row['ciphertext_hash'])) {
            try {
                $row['payload_json'] = $this->encryptor->decrypt($row['payload_json']);
            } catch (SecurityException | SodiumException) {
                // Key rotation or dev session change; return a fallback marker
                $row['payload_json'] = '{"_decryption_failed":true}';
            }
        }

        return $row;
    }
}
