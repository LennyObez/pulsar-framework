<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();

        // Table 1: auth_totp_secrets
        // Stores encrypted TOTP shared secrets per user.
        // Secrets are encrypted at rest via Encryptor (XSalsa20-Poly1305).
        $this->createTotpSecretsTable($connection, $driver);

        // Table 2: auth_recovery_codes
        // Stores BLAKE2b-hashed recovery codes per user.
        $this->createRecoveryCodesTable($connection, $driver);

        // Table 3: auth_totp_replay_guard
        // Tracks used TOTP time steps to prevent replay attacks.
        $this->createReplayGuardTable($connection, $driver);
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS auth_totp_replay_guard');
        $connection->execute('DROP TABLE IF EXISTS auth_recovery_codes');
        $connection->execute('DROP TABLE IF EXISTS auth_totp_secrets');
    }

    private function createTotpSecretsTable(ConnectionInterface $connection, Driver $driver): void
    {
        $timestampType = match ($driver) {
            Driver::PostgreSQL => 'TIMESTAMPTZ',
            Driver::MySQL => 'DATETIME',
            Driver::SQLite => 'TEXT',
        };

        $defaultNow = match ($driver) {
            Driver::PostgreSQL => 'DEFAULT NOW()',
            Driver::MySQL => 'DEFAULT CURRENT_TIMESTAMP',
            Driver::SQLite => "DEFAULT (datetime('now'))",
        };

        $connection->execute(<<<SQL
            CREATE TABLE IF NOT EXISTS auth_totp_secrets (
                user_id VARCHAR(36) NOT NULL,
                encrypted_secret TEXT NOT NULL,
                algorithm VARCHAR(10) NOT NULL DEFAULT 'sha1',
                digits INTEGER NOT NULL DEFAULT 6,
                period INTEGER NOT NULL DEFAULT 30,
                created_at {$timestampType} NOT NULL {$defaultNow},
                updated_at {$timestampType} NOT NULL {$defaultNow},
                PRIMARY KEY (user_id)
            )
            SQL);
    }

    private function createRecoveryCodesTable(ConnectionInterface $connection, Driver $driver): void
    {
        $timestampType = match ($driver) {
            Driver::PostgreSQL => 'TIMESTAMPTZ',
            Driver::MySQL => 'DATETIME',
            Driver::SQLite => 'TEXT',
        };

        $defaultNow = match ($driver) {
            Driver::PostgreSQL => 'DEFAULT NOW()',
            Driver::MySQL => 'DEFAULT CURRENT_TIMESTAMP',
            Driver::SQLite => "DEFAULT (datetime('now'))",
        };

        $connection->execute(<<<SQL
            CREATE TABLE IF NOT EXISTS auth_recovery_codes (
                id VARCHAR(36) NOT NULL,
                user_id VARCHAR(36) NOT NULL,
                code_hash VARCHAR(128) NOT NULL,
                used_at {$timestampType} NULL,
                created_at {$timestampType} NOT NULL {$defaultNow},
                PRIMARY KEY (id)
            )
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_recovery_codes_user ON auth_recovery_codes (user_id)
            SQL);

        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_recovery_codes_user_hash ON auth_recovery_codes (user_id, code_hash)
            SQL);
    }

    private function createReplayGuardTable(ConnectionInterface $connection, Driver $driver): void
    {
        $timestampType = match ($driver) {
            Driver::PostgreSQL => 'TIMESTAMPTZ',
            Driver::MySQL => 'DATETIME',
            Driver::SQLite => 'TEXT',
        };

        $connection->execute(<<<SQL
            CREATE TABLE IF NOT EXISTS auth_totp_replay_guard (
                user_id VARCHAR(36) NOT NULL,
                purpose VARCHAR(20) NOT NULL,
                time_step INTEGER NOT NULL,
                used_at {$timestampType} NOT NULL,
                PRIMARY KEY (user_id, purpose, time_step)
            )
            SQL);

        // Index for efficient pruning of expired entries
        $connection->execute(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_replay_guard_used_at ON auth_totp_replay_guard (used_at)
            SQL);
    }
};
