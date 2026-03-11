<?php

declare(strict_types=1);

namespace Pulsar\Extension\OAuth2\Token;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Row;
use Pulsar\Extension\OAuth2\Contract\AuthorizationCodeRepositoryInterface;
use SodiumException;

use function bin2hex;
use function explode;
use function implode;
use function sodium_crypto_generichash;

/**
 * Database-backed authorization code repository.
 *
 * F385.12: ships a production-grade alternative to
 * {@see InMemoryAuthorizationCodeRepository}, which is explicitly
 * not production-safe (codes vanish on worker restart). This
 * repository persists every code in the configured database with
 * BLAKE2b-hashed lookup (codes are never stored in plaintext)
 * and atomic single-use semantics enforced at the SQL level.
 *
 * Schema (created via {@see installSchema()}):
 *
 *   id                      varchar(64)  PRIMARY KEY
 *   client_id               varchar(255) NOT NULL
 *   subject_id              varchar(255) NOT NULL
 *   redirect_uri            varchar(2048) NOT NULL
 *   scopes                  text          -- space-separated
 *   code_hash               varchar(64)  UNIQUE   -- BLAKE2b hex of plaintext code
 *   code_challenge          varchar(255) NOT NULL
 *   code_challenge_method   varchar(16)  NOT NULL
 *   expires_at              datetime     NOT NULL
 *   issued_at               datetime     NOT NULL
 *   revoked                 tinyint(1)   NOT NULL DEFAULT 0
 *   consumed                tinyint(1)   NOT NULL DEFAULT 0
 *   nonce                   varchar(255) NULL
 *
 * The atomic consume implementation issues a single
 * `UPDATE ... WHERE consumed = 0 AND revoked = 0 AND expires_at > NOW()`
 * and reads `affected_rows` to detect a race-loser. The losing replay
 * sees zero rows and returns `null`.
 */
#[Api(since: '1.0.0')]
final readonly class DbAuthorizationCodeRepository implements AuthorizationCodeRepositoryInterface
{
    /**
     * BLAKE2b context for OAuth2 authorisation-code lookup hashing.
     * Domain-bound so a leaked entry from another subsystem cannot
     * be replayed against this code store.
     */
    private const string HASH_CONTEXT = 'pulsar.oauth2.authcode';

    private const string TABLE = 'oauth2_authorization_codes';

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    /**
     * Create the schema if it does not exist. Safe to call repeatedly.
     */
    public function installSchema(): void
    {
        $driver = $this->connection->driver();
        $sql = match ($driver) {
            Driver::SQLite => $this->sqliteSchema(),
            Driver::MySQL => $this->mysqlSchema(),
            Driver::PostgreSQL => $this->postgresSchema(),
        };

        $this->connection->execute($sql);
    }

    /**
     * @throws SodiumException
     */
    public function persist(AuthorizationCode $code): void
    {
        $this->connection->execute(
            'INSERT INTO ' . self::TABLE . ' '
            . '(id, client_id, subject_id, redirect_uri, scopes, code_hash, '
            . 'code_challenge, code_challenge_method, expires_at, issued_at, '
            . 'revoked, consumed, nonce) '
            . 'VALUES (:id, :client_id, :subject_id, :redirect_uri, :scopes, '
            . ':code_hash, :code_challenge, :code_challenge_method, :expires_at, '
            . ':issued_at, :revoked, :consumed, :nonce)',
            [
                'id' => $code->id,
                'client_id' => $code->clientId,
                'subject_id' => $code->subjectId,
                'redirect_uri' => $code->redirectUri,
                'scopes' => implode(' ', $code->scopes),
                'code_hash' => $code->codeValue !== null ? $this->hashCode($code->codeValue) : null,
                'code_challenge' => $code->codeChallenge,
                'code_challenge_method' => $code->codeChallengeMethod,
                'expires_at' => $code->expiresAt->format('Y-m-d H:i:s'),
                'issued_at' => $code->issuedAt->format('Y-m-d H:i:s'),
                'revoked' => $code->revoked ? 1 : 0,
                'consumed' => 0,
                'nonce' => $code->nonce,
            ],
        );
    }

    /**
     * Atomically consume a code. The single-statement UPDATE WHERE
     * consumed=0 AND revoked=0 AND expires_at > now() makes the
     * one-time-use guarantee race-free at the SQL level: two
     * concurrent consume() calls cannot both read a row that the
     * first call's UPDATE has already toggled to consumed=1.
     *
     * @throws SodiumException
     */
    public function consume(string $codeValue): ?AuthorizationCode
    {
        $hash = $this->hashCode($codeValue);
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return $this->connection->transaction(function (ConnectionInterface $tx) use ($hash, $now): ?AuthorizationCode {
            $affected = $tx->execute(
                'UPDATE ' . self::TABLE . ' '
                . 'SET consumed = 1 '
                . 'WHERE code_hash = :hash '
                . 'AND consumed = 0 '
                . 'AND revoked = 0 '
                . 'AND expires_at > :now',
                ['hash' => $hash, 'now' => $now],
            );

            if ($affected === 0) {
                return null;
            }

            $result = $tx->query(
                'SELECT id, client_id, subject_id, redirect_uri, scopes, '
                . 'code_challenge, code_challenge_method, expires_at, issued_at, '
                . 'revoked, nonce '
                . 'FROM ' . self::TABLE . ' '
                . 'WHERE code_hash = :hash',
                ['hash' => $hash],
            );

            $row = $result->first();
            if ($row === null) {
                return null;
            }

            return $this->hydrate($row);
        });
    }

    public function revoke(string $codeId): void
    {
        $this->connection->execute(
            'UPDATE ' . self::TABLE . ' SET revoked = 1 WHERE id = :id',
            ['id' => $codeId],
        );
    }

    public function isRevoked(string $codeId): bool
    {
        $result = $this->connection->query(
            'SELECT revoked, consumed FROM ' . self::TABLE . ' WHERE id = :id',
            ['id' => $codeId],
        );

        $row = $result->first();
        if ($row === null) {
            return false;
        }

        return $row->getInt('revoked') === 1 || $row->getInt('consumed') === 1;
    }

    private function hydrate(Row $row): AuthorizationCode
    {
        $scopesRaw = $row->getString('scopes');
        $scopes = $scopesRaw === '' ? [] : explode(' ', $scopesRaw);

        return new AuthorizationCode(
            id: $row->getString('id'),
            clientId: $row->getString('client_id'),
            subjectId: $row->getString('subject_id'),
            redirectUri: $row->getString('redirect_uri'),
            scopes: $scopes,
            codeChallenge: $row->getString('code_challenge'),
            codeChallengeMethod: $row->getString('code_challenge_method'),
            expiresAt: new DateTimeImmutable($row->getString('expires_at')),
            issuedAt: new DateTimeImmutable($row->getString('issued_at')),
            revoked: $row->getInt('revoked') === 1,
            codeValue: null,
            nonce: $row->get('nonce') !== null ? $row->getString('nonce') : null,
        );
    }

    /**
     * Domain-bound BLAKE2b digest of a raw authorisation-code value.
     *
     * @throws SodiumException
     */
    private function hashCode(string $codeValue): string
    {
        return bin2hex(sodium_crypto_generichash($codeValue, self::HASH_CONTEXT, 32));
    }

    private function sqliteSchema(): string
    {
        return 'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
            . 'id TEXT PRIMARY KEY, '
            . 'client_id TEXT NOT NULL, '
            . 'subject_id TEXT NOT NULL, '
            . 'redirect_uri TEXT NOT NULL, '
            . 'scopes TEXT NOT NULL DEFAULT \'\', '
            . 'code_hash TEXT, '
            . 'code_challenge TEXT NOT NULL, '
            . 'code_challenge_method TEXT NOT NULL, '
            . 'expires_at TEXT NOT NULL, '
            . 'issued_at TEXT NOT NULL, '
            . 'revoked INTEGER NOT NULL DEFAULT 0, '
            . 'consumed INTEGER NOT NULL DEFAULT 0, '
            . 'nonce TEXT, '
            . 'UNIQUE (code_hash)'
            . ')';
    }

    private function mysqlSchema(): string
    {
        return 'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
            . 'id VARCHAR(64) PRIMARY KEY, '
            . 'client_id VARCHAR(255) NOT NULL, '
            . 'subject_id VARCHAR(255) NOT NULL, '
            . 'redirect_uri VARCHAR(2048) NOT NULL, '
            . 'scopes TEXT NOT NULL, '
            . 'code_hash VARCHAR(64) NULL, '
            . 'code_challenge VARCHAR(255) NOT NULL, '
            . 'code_challenge_method VARCHAR(16) NOT NULL, '
            . 'expires_at DATETIME NOT NULL, '
            . 'issued_at DATETIME NOT NULL, '
            . 'revoked TINYINT(1) NOT NULL DEFAULT 0, '
            . 'consumed TINYINT(1) NOT NULL DEFAULT 0, '
            . 'nonce VARCHAR(255) NULL, '
            . 'UNIQUE KEY uniq_code_hash (code_hash), '
            . 'INDEX idx_expires_at (expires_at)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    }

    private function postgresSchema(): string
    {
        return 'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
            . 'id VARCHAR(64) PRIMARY KEY, '
            . 'client_id VARCHAR(255) NOT NULL, '
            . 'subject_id VARCHAR(255) NOT NULL, '
            . 'redirect_uri VARCHAR(2048) NOT NULL, '
            . 'scopes TEXT NOT NULL DEFAULT \'\', '
            . 'code_hash VARCHAR(64), '
            . 'code_challenge VARCHAR(255) NOT NULL, '
            . 'code_challenge_method VARCHAR(16) NOT NULL, '
            . 'expires_at TIMESTAMP NOT NULL, '
            . 'issued_at TIMESTAMP NOT NULL, '
            . 'revoked SMALLINT NOT NULL DEFAULT 0, '
            . 'consumed SMALLINT NOT NULL DEFAULT 0, '
            . 'nonce VARCHAR(255), '
            . 'CONSTRAINT uniq_code_hash UNIQUE (code_hash)'
            . ')';
    }
}
