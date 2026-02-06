<?php

declare(strict_types=1);

namespace Pulsar\Studio\Console\Storage;

use function array_map;
use function hash;
use function is_string;

use JsonException;
use Override;
use PDOException;
use Pulsar\Api\Internal;
use Pulsar\Security\Crypto\Encryptor;
use Pulsar\Security\Exception\SecurityException;
use Pulsar\Studio\Console\Event\EventEnvelope;
use Pulsar\Studio\Exception\StudioException;
use Random\RandomException;
use RuntimeException;
use SodiumException;

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
        private Encryptor $encryptor,
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

        // Store event with encrypted payload
        // We need to modify the envelope to carry the ciphertext_hash
        // But EventEnvelope is readonly, so we work at the SQL level
        $this->inner->store($envelope, $encrypted, $tenantHash);

        // Update ciphertext_hash after insert
        $ciphertextHash = hash('sha256', $encrypted);
        $pdo = $this->inner->pdo();
        $stmt = $pdo->prepare('UPDATE studio_events SET ciphertext_hash = :hash WHERE event_id = :id');
        $stmt->execute(['hash' => $ciphertextHash, 'id' => $envelope->eventId]);
    }

    /**
     * Store with chain link, encrypting the payload.
     *
     * @throws JsonException If JSON encoding fails
     * @throws PDOException If a non-retryable database error occurs
     * @throws SecurityException If encryption fails
     * @throws StudioException If maximum retry attempts exceeded due to database busy
     * @throws RandomException
     * @throws SodiumException
     */
    public function storeWithChain(
        EventEnvelope $envelope,
        string $payloadJson,
        ?string $tenantHash,
        ?string $chainMacKey,
    ): void {
        $encrypted = $this->encryptor->encrypt($payloadJson);
        $ciphertextHash = hash('sha256', $encrypted);

        // Use the inner store's storeWithChain but with encrypted payload
        // The chain canonical form uses envelope->payloadHash which is the PLAINTEXT hash
        $this->inner->storeWithChain($envelope, $encrypted, $tenantHash, $chainMacKey);

        // Update ciphertext_hash
        $pdo = $this->inner->pdo();
        $stmt = $pdo->prepare('UPDATE studio_events SET ciphertext_hash = :hash WHERE event_id = :id');
        $stmt->execute(['hash' => $ciphertextHash, 'id' => $envelope->eventId]);
    }

    /**
     * @throws RuntimeException If decryption fails
     * @throws SecurityException If decryption fails
     * @throws JsonException If JSON encoding fails
     * @throws SodiumException
     */
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

    /**
     * @throws RuntimeException If decryption fails
     * @throws SecurityException If decryption fails
     * @throws JsonException If JSON encoding fails
     * @throws SodiumException
     */
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
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     * @throws RuntimeException If decryption fails
     * @throws JsonException If JSON encoding fails
     * @throws SecurityException If decryption fails
     * @throws SodiumException
     */
    private function decryptRow(array $row): array
    {
        // Skip decryption for plaintext rows (stored before encryption was enabled).
        // Encrypted rows always have a ciphertext_hash; plaintext rows have NULL.
        if (isset($row['payload_json']) && is_string($row['payload_json']) && isset($row['ciphertext_hash'])) {
            $row['payload_json'] = $this->encryptor->decrypt($row['payload_json']);
        }

        return $row;
    }
}
