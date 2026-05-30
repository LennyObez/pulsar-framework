<?php

declare(strict_types=1);

namespace Pulsar\Auth\Internal\Persistence;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Auth\TwoFactor\ConsumeReason;
use Pulsar\Auth\TwoFactor\ConsumeResult;
use Pulsar\Auth\TwoFactor\RecoveryCodeSet;
use Pulsar\Auth\TwoFactor\RecoveryCodeStoreInterface;
use Pulsar\Database\ConnectionInterface;

use function date;
use function hash_equals;
use function strlen;

/**
 * Database-backed recovery code store for production deployments.
 *
 * Stores recovery code hashes (BLAKE2b) in the `auth_recovery_codes` table.
 * Secrets are never stored in plaintext: only their keyed BLAKE2b hashes
 * are persisted, as produced by RecoveryCodeHasher.
 *
 * Consume is atomic: uses UPDATE ... WHERE used_at IS NULL to ensure
 * only one concurrent request can consume a given code.
 *
 * Uses parameterized queries exclusively to prevent SQL injection (CWE-89).
 */
#[Internal]
final readonly class DatabaseRecoveryCodeStore implements RecoveryCodeStoreInterface
{
    /**
     * Metadata column prefix used to store the set ID, algorithm version,
     * and created-at timestamp alongside the individual code rows.
     *
     * The first row for each user_id carries metadata in the id field as:
     * "meta:{setId}:{algorithmVersion}:{createdAt}"
     *
     * This avoids a separate metadata table.
     */
    private const string META_PREFIX = 'meta:';

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    #[Override]
    public function loadSet(string $identityId): ?RecoveryCodeSet
    {
        $result = $this->connection->query(
            <<<'SQL'
                SELECT id, code_hash, used_at
                FROM auth_recovery_codes
                WHERE user_id = :user_id
                ORDER BY id ASC
                SQL,
            [':user_id' => $identityId],
        );

        if ($result->isEmpty()) {
            return null;
        }

        $codeHashes = [];
        $usedIndices = [];
        $setId = '';
        $algorithmVersion = 2;
        $createdAt = 0;

        $index = 0;

        foreach ($result->rows as $row) {
            $id = $row->getString('id');
            $codeHash = $row->getString('code_hash');
            $usedAt = $row->getNullableString('used_at');

            // First row with meta prefix carries set metadata
            if (str_starts_with($id, self::META_PREFIX)) {
                $parts = explode(':', substr($id, strlen(self::META_PREFIX)));
                $setId = $parts[0] ?? '';
                $algorithmVersion = (int) ($parts[1] ?? 2);
                $createdAt = (int) ($parts[2] ?? 0);
            }

            $codeHashes[] = $codeHash;

            if ($usedAt !== null) {
                $usedIndices[] = $index;
            }

            $index++;
        }

        return new RecoveryCodeSet(
            $setId,
            $codeHashes,
            $usedIndices,
            $algorithmVersion,
            $createdAt,
        );
    }

    #[Override]
    public function consume(string $identityId, string $codeHash): ConsumeResult
    {
        // Load the current set to verify enrollment and find the code
        $result = $this->connection->query(
            <<<'SQL'
                SELECT id, code_hash, used_at
                FROM auth_recovery_codes
                WHERE user_id = :user_id
                ORDER BY id ASC
                SQL,
            [':user_id' => $identityId],
        );

        if ($result->isEmpty()) {
            return ConsumeResult::failure(ConsumeReason::NotEnrolled);
        }

        // Find the target row matching the supplied code hash
        $targetRowId = null;
        $targetUsedAt = null;
        $targetIndex = -1;

        $index = 0;

        foreach ($result->rows as $row) {
            $rowCodeHash = $row->getString('code_hash');

            if (hash_equals($rowCodeHash, $codeHash)) {
                $targetRowId = $row->getString('id');
                $targetUsedAt = $row->getNullableString('used_at');
                $targetIndex = $index;
            }

            $index++;
        }

        if ($targetIndex === -1) {
            return ConsumeResult::failure(ConsumeReason::NotFound);
        }

        if ($targetUsedAt !== null) {
            return ConsumeResult::failure(ConsumeReason::AlreadyUsed);
        }

        // Atomic consume: UPDATE only if used_at IS NULL
        $affected = $this->connection->execute(
            <<<'SQL'
                UPDATE auth_recovery_codes
                SET used_at = :used_at
                WHERE id = :id AND used_at IS NULL
                SQL,
            [
                ':used_at' => date('Y-m-d H:i:s'),
                ':id' => $targetRowId,
            ],
        );

        if ($affected === 0) {
            // Another process consumed it between our SELECT and UPDATE
            return ConsumeResult::failure(ConsumeReason::AlreadyUsed);
        }

        return ConsumeResult::success($targetIndex);
    }

    #[Override]
    public function store(string $identityId, RecoveryCodeSet $set): void
    {
        // Delete all existing codes for this identity
        $this->connection->execute(
            'DELETE FROM auth_recovery_codes WHERE user_id = :user_id',
            [':user_id' => $identityId],
        );

        // Insert new codes with metadata encoded in the first row's ID
        foreach ($set->codeHashes as $index => $codeHash) {
            $rowId = $index === 0
                ? self::META_PREFIX . $set->setId . ':' . $set->algorithmVersion . ':' . $set->createdAt
                : $set->setId . '-' . $index;

            $usedAt = $set->isUsed($index) ? date('Y-m-d H:i:s') : null;

            $this->connection->execute(
                <<<'SQL'
                    INSERT INTO auth_recovery_codes (id, user_id, code_hash, used_at, created_at)
                    VALUES (:id, :user_id, :code_hash, :used_at, :created_at)
                    SQL,
                [
                    ':id' => $rowId,
                    ':user_id' => $identityId,
                    ':code_hash' => $codeHash,
                    ':used_at' => $usedAt,
                    ':created_at' => date('Y-m-d H:i:s'),
                ],
            );
        }
    }
}
