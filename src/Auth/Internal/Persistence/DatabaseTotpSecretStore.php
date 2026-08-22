<?php

declare(strict_types=1);

namespace Pulsar\Auth\Internal\Persistence;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Auth\TwoFactor\TotpSecretStoreInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Security\Crypto\EncryptorInterface;
use SensitiveParameter;

/**
 * Database-backed TOTP secret store for production deployments.
 *
 * Encrypts secrets at rest using the provided Encryptor (AEAD, XSalsa20-Poly1305)
 * before persisting to the `auth_totp_secrets` table. Decrypts on retrieval.
 *
 * Uses parameterized queries exclusively to prevent SQL injection (CWE-89).
 */
#[Internal]
final readonly class DatabaseTotpSecretStore implements TotpSecretStoreInterface
{
    public function __construct(
        private ConnectionInterface $connection,
        private EncryptorInterface $encryptor,
    ) {}

    #[Override]
    public function store(string $identityId, #[SensitiveParameter] string $secret): void
    {
        $encrypted = $this->encryptor->encrypt($secret);
        $now = date('Y-m-d H:i:s');

        // Upsert: INSERT or UPDATE on conflict (driver-aware via standard SQL)
        // Check if exists first, then insert or update
        $existing = $this->connection->query(
            'SELECT user_id FROM auth_totp_secrets WHERE user_id = :user_id',
            [':user_id' => $identityId],
        );

        if ($existing->isEmpty()) {
            $this->connection->execute(
                <<<'SQL'
                    INSERT INTO auth_totp_secrets (user_id, encrypted_secret, created_at, updated_at)
                    VALUES (:user_id, :encrypted_secret, :created_at, :updated_at)
                    SQL,
                [
                    ':user_id' => $identityId,
                    ':encrypted_secret' => $encrypted,
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ],
            );
        } else {
            $this->connection->execute(
                <<<'SQL'
                    UPDATE auth_totp_secrets
                    SET encrypted_secret = :encrypted_secret, updated_at = :updated_at
                    WHERE user_id = :user_id
                    SQL,
                [
                    ':encrypted_secret' => $encrypted,
                    ':updated_at' => $now,
                    ':user_id' => $identityId,
                ],
            );
        }
    }

    #[Override]
    public function retrieve(string $identityId): ?string
    {
        $result = $this->connection->query(
            'SELECT encrypted_secret FROM auth_totp_secrets WHERE user_id = :user_id',
            [':user_id' => $identityId],
        );

        $row = $result->first();

        if ($row === null) {
            return null;
        }

        $encrypted = $row->getString('encrypted_secret');

        return $this->encryptor->decrypt($encrypted);
    }

    #[Override]
    public function delete(string $identityId): void
    {
        $this->connection->execute(
            'DELETE FROM auth_totp_secrets WHERE user_id = :user_id',
            [':user_id' => $identityId],
        );
    }
}
